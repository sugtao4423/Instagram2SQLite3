<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

define('API_PROFILE_URL', 'https://www.instagram.com/api/v1/users/web_profile_info/');
define('API_QUERY_URL', 'https://www.instagram.com/graphql/query/');
define('API_QUERY_POST_DOC_ID', '17991233890457762');
define('API_HTTP_HEADERS', [
    'X-Asbd-Id: 129477',
    'X-Ig-App-Id: 936619743392459',
]);
define('USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

$options = getopt('u:', ['username:']);
$username = $options['u'] ?? $options['username'] ?? null;
if (!isset($username)) {
    echo "Please set username\n";
    echo "  php {$argv[0]} -u {USERNAME}\n";
    echo "  php {$argv[0]} --username {USERNAME}\n";
    exit(1);
}

define('USER_DIR', __DIR__ . '/' . $username);

$userId = getUserId($username);
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
    $json = getPosts($userId, 50, $maxId);

    $breakFlag = false;

    $edges = $json['data']['user']['edge_owner_to_timeline_media']['edges'];
    foreach ($edges as $edge) {
        if ($lastShortcode === $edge['node']['shortcode']) {
            $breakFlag = true;
            break;
        }
        $post = [
            'typename' => $edge['node']['__typename'],
            'text' => $edge['node']['edge_media_to_caption']['edges']['0']['node']['text'],
            'shortcode' => $edge['node']['shortcode'],
            'display_url' => $edge['node']['display_url'],
            'video_url' => $edge['node']['video_url'] ?? null,
            'sidecar_edges' => $edge['node']['edge_sidecar_to_children']['edges'] ?? null,
            'timestamp' => $edge['node']['taken_at_timestamp']
        ];
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

    $hasNextPage = $json['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];
    if ($hasNextPage) {
        $maxId = $json['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
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



function getUserId(string $username): int
{
    $url = API_PROFILE_URL . '?' . http_build_query(['username' => $username]);
    $response = requestApi($url);
    $json = json_decode($response, true);
    $userId = $json['data']['user']['id'] ?? null;
    if ($userId === null || (int)$userId === 0) {
        echo "Error: Can't get user id.\n";
        exit(1);
    }
    return (int)$userId;
}

function getPosts(int $id, int $count, string $maxId): array
{
    $params = http_build_query([
        'doc_id' => API_QUERY_POST_DOC_ID,
        'variables' => json_encode([
            'id' => (string)$id,
            'first' => (string)$count,
            'after' => (string)$maxId
        ])
    ]);
    $url = API_QUERY_URL . '?' . $params;
    $content = requestApi($url);
    return json_decode($content, true);
}

function saveGraphImageOrVideo(array $post): array
{
    $isVideo = $post['video_url'] !== null;
    $url = $isVideo ? $post['video_url'] : $post['display_url'];
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
    foreach ($post['sidecar_edges'] as $edge) {
        $fileName = getPostDate($post) . '-' . $imageCount++;
        $savePath = USER_DIR . '/' . $fileName;

        $urlKey = $edge['node']['is_video'] ? 'video_url' : 'display_url';
        $fileExt = saveMediaFile($edge['node'][$urlKey], $savePath);
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, API_HTTP_HEADERS);
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

function getPostDate(array $post): string
{
    return date('Y-m-d H.i.s', $post['timestamp']);
}
