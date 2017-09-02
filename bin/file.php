<?php

$uri = $_SERVER['REQUEST_URI'];
if (substr($uri, 1, 1) == '/')
    $uri = substr($uri, 1, -1);
$uri = strtok($uri, '?');

if (substr($uri, -5) != '.json') {
    $parts = explode('/', $uri);

    $vender = $parts[2];
    $name = $parts[3];
    $ref = explode('.', $parts[4])[0];

    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $url = $redis->hGet('file', "$vender/$name/$ref");
    if (empty($url)) {
        header("HTTP/1.1 404 Not Found");
        die(404);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERAGENT, 'LU');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    header("Content-Type: $content_type", true, $code);
    echo $res;
} else {
    $gz_path = __DIR__ . '/../storage/' . $uri . '.gz';
    $gz_path = str_replace('%24', '$', $gz_path);
    if (!file_exists($gz_path)) {
        header("HTTP/1.1 404 Not Found");
        die(404);
    }
    $content = file_get_contents($gz_path);
    header("Content-Type: application/json");
    echo gzuncompress($content);
}
