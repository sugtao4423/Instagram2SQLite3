<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

define('API_USER_AGENT', 'Instagram 247.0.0.17.113 Android');
define('API_BASE_URL', 'https://i.instagram.com/api/v1');
define('GRAPHQL_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36');
define('GRAPHQL_QUERY_URL', 'https://www.instagram.com/graphql/query');
define('QUERY_HASH', '69cba40317214236af40e7efa697781d');

$username = @$argv[1];
if (!isset($username)) {
    echo "Please set username\n";
    echo "php {$argv[0]} USERNAME\n";
    exit(1);
}

define('USER_DIR', __DIR__ . "/{$username}");

$userId = getUserId($username);
if (!file_exists(USER_DIR)) {
    mkdir(USER_DIR);
}

$db = new SQLite3(__DIR__ . "/{$username}.db");
$db->exec("CREATE TABLE IF NOT EXISTS '{$username}' (typename TEXT, text TEXT, shortcode TEXT, medias TEXT, timestamp INTEGER UNIQUE)");
$lastShortcode = $db->querySingle("SELECT shortcode from '{$username}' ORDER BY timestamp DESC LIMIT 1");

$posts = [];
$maxId = '';
echo '0 posts done';
while ($maxId !== null) {
    $json = getJson($userId, 50, $maxId);

    $breakFlag = false;

    $edges = $json['data']['user']['edge_owner_to_timeline_media']['edges'];
    foreach ($edges as $edge) {
        if ($lastShortcode == $edge['node']['shortcode']) {
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
    $stmt = $db->prepare("INSERT INTO '{$username}' VALUES (:typename, :text, :shortcode, :medias, :timestamp)");
    $stmt->bindValue(':typename', $post['typename'], SQLITE3_TEXT);
    $stmt->bindValue(':text', $post['text'], SQLITE3_TEXT);
    $stmt->bindValue(':shortcode', $post['shortcode'], SQLITE3_TEXT);
    $stmt->bindValue(':medias', $post['medias'], SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $post['timestamp'], SQLITE3_INTEGER);
    $stmt->execute();
}
echo "\nFinished!\n";



function getUserId(string $username): int
{
    $url = API_BASE_URL . '/users/web_profile_info/?username=' . urlencode($username);
    $response = requestApi($url);
    $json = json_decode($response, true);
    return (int)$json['data']['user']['id'];
}

function getJson(int $id, int $count, string $maxId): array
{
    $variables = json_encode([
        'id' => (string)$id,
        'first' => (string)$count,
        'after' => (string)$maxId
    ]);
    $url = GRAPHQL_QUERY_URL . '/?query_hash=' . QUERY_HASH . '&variables=' . urlencode($variables);
    $content = requestGraphQL($url);
    return json_decode($content, true);
}

function saveGraphImageOrVideo(array $post): array
{
    $isVideo = $post['video_url'] !== null;
    $url = $isVideo ? $post['video_url'] : $post['display_url'];
    $fileName = getPostDate($post);
    $savePath = USER_DIR . "/{$fileName}";
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
        $savePath = USER_DIR . "/{$fileName}";

        if ($edge['node']['is_video']) {
            $fileExt = saveMediaFile($edge['node']['video_url'], $savePath);
        } else {
            $fileExt = saveMediaFile($edge['node']['display_url'], $savePath);
        }
        $imageNames[] = $fileName . '.' . $fileExt;
    }
    $post['medias'] = implode(',', $imageNames);
    return $post;
}

function request(string $url, string $userAgent): string
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    do {
        sleep(1);
        $response = curl_exec($ch);
    } while ($response === false);
    curl_close($ch);
    return $response;
}

function requestApi(string $url): string
{
    return request($url, API_USER_AGENT);
}

function requestGraphQL(string $url): string
{
    return request($url, GRAPHQL_USER_AGENT);
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
    curl_setopt($ch, CURLOPT_USERAGENT, GRAPHQL_USER_AGENT);
    do {
        sleep(1);
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
