<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

define('USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.72 Safari/537.36');
define('BASE_URL', 'https://www.instagram.com');
define('MEDIA_URL', 'https://www.instagram.com/graphql/query/?query_hash=42d2750e44dbac713ff30130659cd891&variables=');
define('MEDIA_LINK', 'https://www.instagram.com/p/');

$username = @$argv[1];
if (!isset($username)) {
    echo "Please set username\n";
    echo "php {$argv[0]} USERNAME SESSION_ID\n";
    exit(1);
}

$sessionId = @$argv[2];
if (!isset($sessionId)) {
    echo "Please set your session id\n";
    echo "php {$argv[0]} '${username}' SESSION_ID\n";
    exit(1);
}
define('SESSION_ID', $sessionId);

define('USER_DIR', __DIR__ . "/${username}");

$userId = getUserId($username);
if (!file_exists(USER_DIR)) {
    mkdir(USER_DIR);
}

$db = new SQLite3(__DIR__ . "/${username}.db");
$db->exec("CREATE TABLE IF NOT EXISTS '${username}' (typename TEXT, text TEXT, shortcode TEXT, medias TEXT, timestamp INTEGER UNIQUE)");
$lastShortcode = $db->querySingle("SELECT shortcode from '${username}' ORDER BY timestamp DESC LIMIT 1");

$posts = [];
$maxId = '';
echo '0 posts done';
while ($maxId !== null) {
    sleep(1);
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
            'timestamp' => $edge['node']['taken_at_timestamp']
        ];
        switch ($post['typename']) {
            case 'GraphImage':
                sleep(1);
                $post = saveGraphImage($post);
                break;

            case 'GraphSidecar':
                sleep(1);
                $post = saveGraphSidecar($post);
                break;

            case 'GraphVideo':
                sleep(1);
                $post = saveGraphVideo($post);
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
    $stmt = $db->prepare("INSERT INTO '${username}' VALUES (:typename, :text, :shortcode, :medias, :timestamp)");
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
    $html = safeFileGet(BASE_URL . "/${username}");
    preg_match('|<script type="text/javascript">window._sharedData = (.*?);</script>|', $html, $m);
    $json = json_decode($m[1], true);
    return (int)$json['entry_data']['ProfilePage']['0']['graphql']['user']['id'];
}

function getJson(int $id, int $count, string $maxId): array
{
    $variables = json_encode([
        'id' => (string)$id,
        'first' => (string)$count,
        'after' => (string)$maxId
    ]);
    $url = MEDIA_URL . urlencode($variables);
    $content = safeFileGet($url, false);
    return json_decode($content, true);
}

function saveGraphImage(array $post): array
{
    $data = safeFileGet($post['display_url'], true);
    $image = $data[0];
    $imageExt = $data[1];
    $imageDate = getPostDate($post);
    $imageName = "${imageDate}.${imageExt}";
    file_put_contents(USER_DIR . "/${imageName}", $image);
    $post['medias'] = $imageName;
    return $post;
}

function extractAdditionalJson(string $html): array
{
    preg_match('|<script type="text/javascript">window.__additionalDataLoaded\(.+?,(.+?)\);</script>|', $html, $m);
    return json_decode($m[1], true);
}

function saveGraphSidecar(array $post): array
{
    $html = safeFileGet(MEDIA_LINK . $post['shortcode']);
    $json = extractAdditionalJson($html);
    $edges = $json['graphql']['shortcode_media']['edge_sidecar_to_children']['edges'];
    $imageCount = 1;
    $imageNames = '';
    foreach ($edges as $edge) {
        if ($edge['node']['is_video']) {
            $data = safeFileGet($edge['node']['video_url'], true);
        } else {
            $data = safeFileGet($edge['node']['display_url'], true);
        }
        $image = $data[0];
        $imageExt = $data[1];
        $imageDate = getPostDate($post);
        $imageName = "${imageDate}-" . $imageCount++ . ".${imageExt}";
        $imageNames .= "${imageName},";
        file_put_contents(USER_DIR . "/${imageName}", $image);
    }
    $imageNames = substr($imageNames, 0, strlen($imageNames) - 1);
    $post['medias'] = $imageNames;
    return $post;
}

function saveGraphVideo(array $post): array
{
    $html = safeFileGet(MEDIA_LINK . $post['shortcode']);
    $json = extractAdditionalJson($html);
    $videoUrl = $json['graphql']['shortcode_media']['video_url'];
    $data = safeFileGet($videoUrl, true);
    $video = $data[0];
    $videoExt = $data[1];
    $videoDate = getPostDate($post);
    $videoName = "${videoDate}.${videoExt}";
    file_put_contents(USER_DIR . "/${videoName}", $video);
    $post['medias'] = $videoName;
    return $post;
}

function safeFileGet(string $url, bool $includeExt = false)
{
    while (true) {
        sleep(1);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . USER_AGENT . "\r\n" .
                    'Cookie: ds_user_id=; sessionid=' . SESSION_ID . "\r\n"
            ]
        ]);
        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            sleep(1);
            continue;
        } else {
            if (!$includeExt) {
                return $data;
            }
            $fileExt = null;
            foreach ($http_response_header as $head) {
                if (($mimeType = preg_replace('/^Content-Type: (image|video)\//', '', $head)) !== $head) {
                    $fileExt = ($mimeType === 'jpeg') ? 'jpg' : $mimeType;
                    break;
                }
            }
            return [$data, $fileExt];
        }
    }
}

function getPostDate(array $post): string
{
    return date('Y-m-d H.i.s', $post['timestamp']);
}
