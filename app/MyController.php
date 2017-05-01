<?php

namespace App;

use Swoole\Coroutine\Http\Client;

class MyController
{
    public function action($request, $response, $parameters)
    {
        $cli = new Client('packagist.laravel-china.org', 443, true);
        $cli->set(['timeout' => 30]);
        $cli->get('/packages.json');
        $json_content = json_decode($cli->body, true);
        $json_content['mirrors'] = [
            [
                "dist-url" => "http://localhost:9501/file/%package%/%reference%.%type%",
                "preferred" => true
            ],
        ];

        $json_content['providers-url'] = '/package' . $json_content['providers-url'];
        foreach ($json_content['provider-includes'] as $p => $c) {
            unset($json_content['provider-includes'][$p]);
            $json_content['provider-includes']['provider/' . $p] = $c;
        }
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($json_content, JSON_PRETTY_PRINT));
    }
}