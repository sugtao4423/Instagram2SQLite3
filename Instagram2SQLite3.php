<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

define('USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.87 Safari/537.36');
define('BASE_URL', 'https://www.instagram.com');
define('MEDIA_URL', 'https://www.instagram.com/graphql/query/?query_hash=42323d64886122307be10013ad2dcc44&variables=');
define('MEDIA_LINK', 'https://www.instagram.com/p/');

$username = $argv[1];
if(!isset($username)){
    echo "Please set username\n";
    echo "php {$argv[0]} USERNAME\n";
    die();
}

define('USER_DIR', __DIR__ . "/${username}");

$userId = getUserId($username);
if(!file_exists(USER_DIR)){
    mkdir(USER_DIR);
}

$db = new SQLite3(__DIR__ . "/${username}.db");
$db->exec("CREATE TABLE IF NOT EXISTS '${username}' (typename TEXT, text TEXT, shortcode TEXT, medias TEXT, timestamp INTEGER UNIQUE)");
$lastShortcode = $db->querySingle("SELECT shortcode from '${username}' ORDER BY timestamp DESC LIMIT 1");

$rhxgis = getRhxGis();

$posts = array();
$maxId = '';
while($maxId !== null){
    sleep(1);
    $json = getJson($userId, 50, $maxId);

    $breakFlag = false;

    $edges = $json['data']['user']['edge_owner_to_timeline_media']['edges'];
    foreach($edges as $edge){
        if($lastShortcode == $edge['node']['shortcode']){
            $breakFlag = true;
            break;
        }
        array_push($posts, array(
            'typename' => $edge['node']['__typename'],
            'text' => $edge['node']['edge_media_to_caption']['edges']['0']['node']['text'],
            'shortcode' => $edge['node']['shortcode'],
            'display_url' => $edge['node']['display_url'],
            'timestamp' => $edge['node']['taken_at_timestamp']
        ));
    }

    if($breakFlag){
        break;
    }

    $hasNextPage = $json['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];
    if($hasNextPage){
        $maxId = $json['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
    }else{
        $maxId = null;
    }
}

$posts = array_reverse($posts);
foreach($posts as $p){
    switch($p['typename']){
    case 'GraphImage':
        sleep(1);
        saveGraphImage($p);
        break;

    case 'GraphSidecar':
        sleep(1);
        saveGraphSidecar($p);
        break;

    case 'GraphVideo':
        sleep(1);
        saveGraphVideo($p);
        break;

    default:
        echo "Unknown post\n";
        echo "Shortcode: {$p['shortcode']}";
    }
}
echo "Finished!\n";
echo 'Add count: ' . count($posts) . "\n";

function getUserId(string $username): int{
    $html = safeFileGet(BASE_URL . "/${username}");
    $json = extractJson($html);
    return (int)$json['entry_data']['ProfilePage']['0']['graphql']['user']['id'];
}

function getRhxGis(): string{
    $context = stream_context_create(array(
        'http' => array(
            'method'  => 'GET',
            'header'  => 'User-Agent: ' . USER_AGENT . "\r\n"
        ))
    );
    $html = safeFileGet(BASE_URL, false, $context);
    $json = extractJson($html);
    return $json['rhx_gis'];
}

function extractJson(string $html): array{
    preg_match('|<script type="text/javascript">window._sharedData = (.*?);</script>|', $html, $m);
    return json_decode($m[1], true);
}

function getJson(int $id, int $count, string $maxId): array{
    global $rhxgis;
    $variables = json_encode([
        'id' => (string)$id,
        'first' => (string)$count,
        'after' => (string)$maxId
    ]);
    $context = stream_context_create(array(
        'http' => array(
            'method'  => 'GET',
            'header'  =>
                'User-Agent: ' . USER_AGENT . "\r\n" .
                'x-instagram-gis: ' . md5(implode(':', [$rhxgis, $variables])) . "\r\n"
        ))
    );
    $url = MEDIA_URL . urlencode($variables);
    $content = safeFileGet($url, false, $context);
    return json_decode($content, true);
}

function saveGraphImage(array $post){
    $data = safeFileGet($post['display_url'], true);
    $image = $data[0];
    $imageExt = $data[1];
    $imageDate = getPostDate($post);
    $imageName = "${imageDate}.${imageExt}";
    file_put_contents(USER_DIR . "/${imageName}", $image);
    insertDB($post, $imageName);
}

function saveGraphSidecar(array $post){
    $html = safeFileGet(MEDIA_LINK . $post['shortcode']);
    $json = extractJson($html);
    $edges = $json['entry_data']['PostPage']['0']['graphql']['shortcode_media']['edge_sidecar_to_children']['edges'];
    $imageCount = 1;
    $imageNames = '';
    foreach($edges as $edge){
        if($edge['node']['is_video']){
            $data = safeFileGet($edge['node']['video_url'], true);
        }else{
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
    insertDB($post, $imageNames);
}

function saveGraphVideo(array $post){
    $html = safeFileGet(MEDIA_LINK . $post['shortcode']);
    $json = extractJson($html);
    $videoUrl = $json['entry_data']['PostPage']['0']['graphql']['shortcode_media']['video_url'];
    $data = safeFileGet($videoUrl, true);
    $video = $data[0];
    $videoExt = $data[1];
    $videoDate = getPostDate($post);
    $videoName = "${videoDate}.${videoExt}";
    file_put_contents(USER_DIR . "/${videoName}", $video);
    insertDB($post, $videoName);
}

function safeFileGet(string $url, bool $includeExt = false, $context = null){
    while(true){
        sleep(1);
        $data = @file_get_contents($url, false, $context);

        if($data === false){
            sleep(1);
            continue;
        }else{
            if(!$includeExt){
                return $data;
            }
            $fileExt = null;
            foreach($http_response_header as $head){
                if(($mimeType = preg_replace('/^Content-Type: (image|video)\//', '', $head)) !== $head){
                    $fileExt = ($mimeType === 'jpeg') ? 'jpg' : $mimeType;
                    break;
                }
            }
            return array($data, $fileExt);
        }
    }
}

function getPostDate(array $post): string{
    return date('Y-m-d H.i.s', $post['timestamp']);
}

function insertDB(array $post, string $medias){
    global $db, $username;
    $text = str_replace("'", "''", $post['text']);
    $exec = "INSERT INTO '${username}' VALUES ('{$post['typename']}', '${text}', '{$post['shortcode']}', '${medias}', {$post['timestamp']})";
    $db->exec($exec);
}

