<?php

namespace App;

use Swoole\Coroutine\Http\Client;

class Package
{
    public function action($request, $response, $parameters)
    {
        $response->header('Content-Type', 'application/json');

        $cache_path = $GLOBALS['STOR_DIR'] . $request->server['request_uri'];
        if (!file_exists($cache_path)) {
            $cli = new Client('packagist.laravel-china.org', 443, true);
            $cli->set(['timeout' => 120]);
            $cli->get('/' . $parameters['subPath']);

            mkdir(dirname($cache_path), 0777, true);
            file_put_contents($cache_path, $cli->body);

            $json_content = json_decode($cli->body, true);

            foreach ($json_content['packages'] as $pkg => $c) {
                $data = [];
                foreach ($c as $version => $c) {
                    $hash = $c['dist']['reference'];
                    $url = $c['dist']['url'];
                    $data[$hash] = $url;
                }
                $meta_path = $GLOBALS['STOR_DIR'] . '/package/' . $pkg . '.json';
                mkdir(dirname($meta_path), 0777, true);
                file_put_contents($meta_path, json_encode($data,JSON_PRETTY_PRINT));
            }

            $response->end($cli->body);
        } else {
            $response->end(file_get_contents($cache_path));
        }
    }
}