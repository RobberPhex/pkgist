<?php

$uri = $_SERVER['REQUEST_URI'];
if (substr($uri, 1, 1) == '/')
    $uri = substr($uri, 1, -1);

$parts = explode('/', $uri);


$vender = $parts[2];
$name = $parts[3];
$ref = explode('.', $parts[4])[0];

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$url = $redis->hGet("$vender/$name", $ref);

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
