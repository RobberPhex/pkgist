<?php

namespace App;

use Swoole\Coroutine\Http\Client;
use GuzzleHttp\Psr7\Request;
use \GuzzleHttp;

class File
{
    public function action($request, $response, $parameters)
    {
        $cache_path = $GLOBALS['STOR_DIR'] . $request->server['request_uri'];

        if (!file_exists($cache_path)) {
            $comps = explode('/', $request->server['request_uri']);
            $json_content = json_decode(file_get_contents(
                $GLOBALS['STOR_DIR'] . '/package/' . $comps[2] . '/' . $comps[3] . '.json'
            ), true);
            $comps = explode('.', $comps[4]);
            $origin_url = $json_content[$comps[0]];

            $client = new \GuzzleHttp\Client();
            $request=new Request('GET', $origin_url);
            $res = $client->send($request);
            $body=$res->getBody();


            mkdir(dirname($cache_path), 0777, true);
            file_put_contents($cache_path,$body);

            $response->end($body);
        } else {
            $response->end(file_get_contents($cache_path));
        }
    }
}