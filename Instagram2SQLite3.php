<?php
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

$userId = getUserId($username);
if(!file_exists("./${username}")){
    mkdir("./${username}");
}

$db = new SQLite3("./${username}.db");
$db->exec("CREATE TABLE IF NOT EXISTS ${username} (typename TEXT, text TEXT, shortcode TEXT, medias TEXT, timestamp INTEGER UNIQUE)");
$lastShortcode = $db->querySingle("SELECT shortcode from ${username} ORDER BY timestamp DESC LIMIT 1");

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
        saveGraphImage($username, $p);
        break;

    case 'GraphSidecar':
        sleep(1);
        saveGraphSidecar($username, $p);
        break;

    case 'GraphVideo':
        sleep(1);
        saveGraphVideo($username, $p);
        break;

    default:
        echo "Unknown post\n";
        echo "Shortcode: {$p['shortcode']}";
    }
}
echo "Finished!\n";
echo 'Add count: ' . count($posts) . "\n";

function getUserId($username){
    $html = file_get_contents(BASE_URL . "/${username}");
    $json = extractJson($html);
    return $json['entry_data']['ProfilePage']['0']['graphql']['user']['id'];
}

function getRhxGis(){
    $context = stream_context_create(array(
        'http' => array(
            'method'  => 'GET',
            'header'  => 'User-Agent: ' . USER_AGENT . "\r\n"
        ))
    );
    $html = file_get_contents(BASE_URL, false, $context);
    $json = extractJson($html);
    return $json['rhx_gis'];
}

function extractJson($html){
    preg_match('|<script type="text/javascript">window._sharedData = (.*?);</script>|', $html, $m);
    return json_decode($m[1], true);
}

function getJson($id, $count, $maxId){
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
    $content = file_get_contents($url, false, $context);
    return json_decode($content, true);
}

function saveGraphImage($username, $post){
    $image = file_get_contents($post['display_url']);
    $imageExt = getMediaExt($http_response_header);
    $imageDate = getPostDate($post);
    $imageName = "${imageDate}.${imageExt}";
    file_put_contents("./${username}/${imageName}", $image);
    insertDB($username, $post, $imageName);
}

function saveGraphSidecar($username, $post){
    $html = file_get_contents(MEDIA_LINK . $post['shortcode']);
    $json = extractJson($html);
    $edges = $json['entry_data']['PostPage']['0']['graphql']['shortcode_media']['edge_sidecar_to_children']['edges'];
    $imageCount = 1;
    $imageNames = '';
    foreach($edges as $edge){
        sleep(1);
        $image = file_get_contents($edge['node']['display_url']);
        $imageExt = getMediaExt($http_response_header);
        $imageDate = getPostDate($post);
        $imageName = "${imageDate}-" . $imageCount++ . ".${imageExt}";
        $imageNames .= "${imageName},";
        file_put_contents("./${username}/${imageName}", $image);
    }
    $imageNames = substr($imageNames, 0, strlen($imageNames) - 1);
    insertDB($username, $post, $imageNames);
}

function saveGraphVideo($username, $post){
    $html = file_get_contents(MEDIA_LINK . $post['shortcode']);
    $json = extractJson($html);
    $videoUrl = $json['entry_data']['PostPage']['0']['graphql']['shortcode_media']['video_url'];
    $video = file_get_contents($videoUrl);
    $videoExt = getMediaExt($http_response_header);
    $videoDate = getPostDate($post);
    $videoName = "${videoDate}.${videoExt}";
    file_put_contents("./${username}/${videoName}", $video);
    insertDB($username, $post, $videoName);
}

function getMediaExt($http_header){
    for($i = 0; $i < count($http_header); $i++){
        if(($mimeType = preg_replace('/^Content-Type: (image|video)\//', '', $http_header[$i])) !== $http_header[$i]){
            $mediaExt = ($mimeType === 'jpeg') ? 'jpg' : $mimeType;
            break;
        }
    }
    return $mediaExt;
}

function getPostDate($post){
    return date('Y-m-d H.i.s', $post['timestamp']);
}

function insertDB($username, $post, $medias){
    global $db;
    $text = str_replace("'", "''", $post['text']);
    $exec = "INSERT INTO ${username} VALUES ('{$post['typename']}', '${text}', '{$post['shortcode']}', '${medias}', {$post['timestamp']})";
    $db->exec($exec);
}

