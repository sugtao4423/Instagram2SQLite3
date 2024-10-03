<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

define('API_PROFILE_URL', 'https://www.instagram.com/api/v1/users/web_profile_info/');
define('API_QUERY_URL', 'https://www.instagram.com/graphql/query');
define('USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36');

$options = getopt('u:c:', ['username:', 'cookie:']);
$username = $options['u'] ?? $options['username'] ?? null;
if (!isset($username)) {
    echo "Please set username\n";
    echo "  php {$argv[0]} -u {USERNAME} [-c {COOKIE}]\n";
    echo "  php {$argv[0]} --username {USERNAME} [--cookie {COOKIE}]\n";
    exit(1);
}
$cookie = $options['c'] ?? $options['cookie'] ?? null;
define('API_COOKIE', $cookie);

define('USER_DIR', __DIR__ . '/' . $username);

if (!file_exists(USER_DIR)) {
    mkdir(USER_DIR);
}

$dbPath = __DIR__ . "/{$username}.db";
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS '{$username}' (typename TEXT, text TEXT, shortcode TEXT, medias TEXT, timestamp INTEGER UNIQUE)");
$lastShortcode = $pdo
    ->query("SELECT shortcode from '{$username}' ORDER BY timestamp DESC LIMIT 1")
    ->fetch(PDO::FETCH_NUM)[0] ?? null;

$posts = [];
$maxId = '';
echo '0 posts done';
while ($maxId !== null) {
    $json = getPosts($username, 50, $maxId);

    $breakFlag = false;

    $xdt = $json['data']['xdt_api__v1__feed__user_timeline_graphql_connection'];
    foreach ($xdt['edges'] as $edge) {
        $post = convertPost($edge['node']);
        if ($lastShortcode === $post['shortcode']) {
            $breakFlag = true;
            break;
        }

        switch ($post['typename']) {
            case 'GraphImage':
            case 'GraphVideo':
                $post = saveGraphImageOrVideo($post);
                break;

            case 'GraphSidecar':
                $post = saveGraphSidecar($post);
                break;

            default:
                echo "Unknown post\n";
                echo "Shortcode: {$post['shortcode']}";
        }

        $posts[] = $post;
        echo "\r";
        echo count($posts) . ' posts done';
    }

    if ($breakFlag) {
        break;
    }

    $hasNextPage = $xdt['page_info']['has_next_page'];
    if ($hasNextPage) {
        $maxId = $xdt['page_info']['end_cursor'];
    } else {
        $maxId = null;
    }
}

$posts = array_reverse($posts);
foreach ($posts as $post) {
    $stmt = $pdo->prepare("INSERT INTO '{$username}' VALUES (?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $post['typename'], PDO::PARAM_STR);
    $stmt->bindValue(2, $post['text'], PDO::PARAM_STR);
    $stmt->bindValue(3, $post['shortcode'], PDO::PARAM_STR);
    $stmt->bindValue(4, $post['medias'], PDO::PARAM_STR);
    $stmt->bindValue(5, $post['timestamp'], PDO::PARAM_INT);
    $stmt->execute();
}
echo "\nFinished!\n";

function convertType(int $mediaType): string
{
    switch ($mediaType) {
        case 1:
            return 'GraphImage';
        case 2:
            return 'GraphVideo';
        case 8:
            return 'GraphSidecar';
        default:
            throw new Exception("Unknown media type: {$mediaType}");
    }
}

function getHiResImageUrl(array $candidates): string
{
    $resolutions = array_map(function ($c) {
        return [
            'url' => $c['url'],
            'resolution' => $c['width'] * $c['height'],
        ];
    }, $candidates);
    usort($resolutions, fn($a, $b) => $b['resolution'] - $a['resolution']);
    return $resolutions[0]['url'];
}

function getHiResVideoUrl(?array $videoVersions): ?string
{
    return $videoVersions ? getHiResImageUrl($videoVersions) : null;
}

function convertGraphSidecar(?array $carouselMedias): array
{
    if ($carouselMedias === null) {
        return [];
    }
    return array_map(function ($media) {
        switch ($media['media_type']) {
            case 1:
                return [
                    'is_video' => false,
                    'url' => getHiResImageUrl($media['image_versions2']['candidates'])
                ];
            case 2:
                return [
                    'is_video' => true,
                    'url' => getHiResVideoUrl($media['video_versions'])
                ];
            default:
                throw new Exception("Unknown media type: {$media['media_type']}");
        }
    }, $carouselMedias);
}

function convertPost(array $node): array
{
    return [
        'typename' => convertType($node['media_type']),
        'text' => $node['caption']['text'],
        'shortcode' => $node['code'],
        'image_url' => getHiResImageUrl($node['image_versions2']['candidates']),
        'video_url' => getHiResVideoUrl($node['video_versions']),
        'carousel_medias' => convertGraphSidecar($node['carousel_media']),
        'timestamp' => $node['taken_at'],
    ];
}

function getPosts(string $username, int $count, string $maxId): array
{
    $params = http_build_query([
        'doc_id' => '8363144743749214',
        'variables' => json_encode([
            'username' => $username,
            'first' => $count,
            'after' => $maxId,
            'before' => null,
            'last' => null,
            'data' => [
                'count' => $count,
                "include_relationship_info" => true,
                "latest_besties_reel_media" => true,
                "latest_reel_media" => true,
            ],
            '__relay_internal__pv__PolarisIsLoggedInrelayprovider' => true,
            '__relay_internal__pv__PolarisFeedShareMenurelayprovider' => false,
        ])
    ]);
    $url = API_QUERY_URL . '?' . $params;
    $content = requestApi($url);
    return json_decode($content, true);
}


function getPostDate(array $post): string
{
    return date('Y-m-d H.i.s', $post['timestamp']);
}

function saveGraphImageOrVideo(array $post): array
{
    $isVideo = $post['video_url'] !== null;
    $url = $isVideo ? $post['video_url'] : $post['image_url'];
    $fileName = getPostDate($post);
    $savePath = USER_DIR . '/' . $fileName;
    $fileExt = saveMediaFile($url, $savePath);
    $post['medias'] = $fileName . '.' . $fileExt;
    return $post;
}

function saveGraphSidecar(array $post): array
{
    $imageCount = 1;
    $imageNames = [];
    foreach ($post['carousel_medias'] as $m) {
        $fileName = getPostDate($post) . '-' . $imageCount++;
        $savePath = USER_DIR . '/' . $fileName;

        $fileExt = saveMediaFile($m['url'], $savePath);
        $imageNames[] = $fileName . '.' . $fileExt;
    }
    $post['medias'] = implode(',', $imageNames);
    return $post;
}

function requestApi(string $url): string
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
    if (API_COOKIE !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, API_COOKIE);
    }
    do {
        sleep(1);
        $response = curl_exec($ch);
    } while ($response === false);
    curl_close($ch);
    return $response;
}

/**
 * @param string $url url of the media file
 * @param string $savePath path to save file `eg. /tmp/file`
 * @return string extension of file `eg. jpg`
 */
function saveMediaFile(string $url, string $savePath): string
{
    $ch = curl_init($url);
    $fp = fopen($savePath, 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    do {
        usleep(200 * 1000);
        $result = curl_exec($ch);
    } while ($result === false);
    $mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $ext = preg_replace('/^(image|video)\//', '', $mimeType);
    $ext = str_replace('jpeg', 'jpg', $ext);
    fclose($fp);
    curl_close($ch);

    rename($savePath, $savePath . '.' . $ext);
    return $ext;
}
