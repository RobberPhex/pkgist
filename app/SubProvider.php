<?php

namespace App;

use Swoole\Coroutine\Http\Client;

class SubProvider
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
            $response->end($cli->body);
        } else {
            $response->end(file_get_contents($cache_path));
        }
    }
}