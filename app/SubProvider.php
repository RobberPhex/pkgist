<?php

namespace App;


use App\Lib\Buffer;
use React\Http\Request;
use React\Http\Response;
use React;

use React\HttpClient\Factory as ClientFactory;
use React\HttpClient\Response as ClientResponse;

class SubProvider
{
    public function action(Request $request, Response $response, $parameters)
    {
        $response->writeHead(200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=86400'
        ]);

        $cache_path = $GLOBALS['STOR_DIR'] . $request->getPath();

        if (!file_exists($cache_path)) {
            $loop = $GLOBALS['LOOP'];
            $resolverFactory = new React\Dns\Resolver\Factory();
            $resolver = $resolverFactory->create('8.8.8.8', $loop);
            $factory = new ClientFactory();
            $client = $factory->create($loop, $resolver);

            $request = $client->request(
                'GET',
                'http://packagist.org/p/provider-' . $parameters['subPath']
            );
            $request->on('response', function (ClientResponse $resp) use ($response, $loop, $cache_path) {
                $buf = new Buffer();
                $resp->on('data', function ($chunk) use ($response, &$buf) {
                    $response->write($chunk);
                    $buf->write($chunk);
                });
                $resp->on('end', function () use ($response, &$buf, $cache_path) {
                    if (!is_dir(dirname($cache_path)))
                        mkdir(dirname($cache_path), 0777, true);
                    file_put_contents($cache_path, $buf->read(), LOCK_EX);
                    $buf->clear();
                    $response->end();
                });
            });
            $request->end();
        } else {
            $response->end(file_get_contents($cache_path));
        }
    }
}