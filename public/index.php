<?php

require __DIR__ . '/../vendor/autoload.php';

$uri = $_SERVER['REQUEST_URI'];
if (substr($uri, 1, 1) == '/')
    $uri = substr($uri, 1, -1);
$uri = strtok($uri, '?');

if (substr($uri, -5) != '.json') {
    $parts = explode('/', $uri);

    if ($parts[1] == 'dl') {
        $encoded_url = $parts[2];
        $origin_url = base64_decode($encoded_url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $origin_url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LU');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $res = curl_exec($ch);
        if (curl_errno($ch)) {
            header("HTTP/1.1 404 Not Found");
            die(404);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        header("Content-Type: $content_type", true, $code);
        header("Cache-Control: max-age=" . (24 * 60 * 60));
        echo $res;
    } else {
        header("HTTP/1.1 404 Not Found");
        die(404);
    }
} else {
    $gz_path = __DIR__ . '/../storage/' . $uri . '.gz';
    $gz_path = str_replace('%24', '$', $gz_path);
    if (!file_exists($gz_path)) {
        header("HTTP/1.1 404 Not Found");
        die(404);
    }
    $content = file_get_contents($gz_path);
    header("Content-Type: application/json");
    if ($uri == '/packages.json') {
        header("Cache-Control: max-age=15");
    } else {
        header("Cache-Control: max-age=" . (24 * 60 * 60));
    }
    echo zlib_decode($content);
}
