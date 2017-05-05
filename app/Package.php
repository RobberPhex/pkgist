<?php

namespace App;

use App\Lib\Buffer;
use React\Http\Request;
use React\Http\Response;
use React;

use React\HttpClient\Factory as ClientFactory;
use React\HttpClient\Response as ClientResponse;

class Package
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
                'https://packagist.org/p/' . $parameters['subPath']
            );
            $request->on('response', function (ClientResponse $resp) use ($response, $loop, $cache_path) {
                $buf = new Buffer();
                $resp->on('data', function ($chunk) use ($response, &$buf) {
                    $response->write($chunk);
                    $buf->write($chunk);
                });
                $resp->on('end', function () use ($response, &$buf, $cache_path) {
                    $response->end();

                    if (!is_dir(dirname($cache_path)))
                        mkdir(dirname($cache_path), 0777, true);
                    file_put_contents($cache_path, $buf->read(), LOCK_EX);

                    $json_content = json_decode($buf->read(), true);
                    foreach ($json_content['packages'] as $pkg => $c) {
                        $data = [];
                        foreach ($c as $version => $c) {
                            $hash = $c['dist']['reference'];
                            $url = $c['dist']['url'];
                            $data[$hash] = $url;
                        }
                        $meta_path = $GLOBALS['STOR_DIR'] . '/p/' . $pkg . '.json';
                        if(!is_dir(dirname($meta_path)))
                            mkdir(dirname($meta_path), 0777, true);
                        file_put_contents($meta_path, json_encode($data, JSON_PRETTY_PRINT));
                    }

                    $buf->clear();
                });
            });
            $request->end();
        } else {
            $response->end(file_get_contents($cache_path));
        }
    }
}