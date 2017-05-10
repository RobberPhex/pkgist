<?php

namespace App\Controller;


use App\Config;
use App\Controller;
use App\Lib\Buffer;
use React\Dns\Resolver\Factory as DnsFactory;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use React\HttpClient\Factory as ClientFactory;
use React\HttpClient\Response as ClientResponse;
use RuntimeException;

class Package implements Controller
{
    private $loop;
    private $config;

    public function __construct(LoopInterface $loop, Config $config)
    {
        $this->loop = $loop;
        $this->config = $config;
    }

    public function action(Request $request, Response $response, $parameters)
    {
        $storage_dir = $this->config->storage_dir;

        $response->writeHead(200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=86400'
        ]);

        $cache_file = $this->config->storage_dir . '/p/' . $parameters['subPath'];

        if (!file_exists($cache_file) || isset($request->getQuery()['purge'])) {
            $loop = $this->loop;
            $resolverFactory = new DnsFactory();
            $resolver = $resolverFactory->create('8.8.8.8', $loop);
            $factory = new ClientFactory();
            $client = $factory->create($loop, $resolver);

            $request = $client->request(
                'GET',
                'https://packagist.org/p/' . $parameters['subPath']
            );
            $request->on('response', function (ClientResponse $resp) use ($response, $loop, $storage_dir, $cache_file) {
                $buf = new Buffer();
                $resp->on('data', function ($chunk) use ($response, &$buf) {
                    $response->write($chunk);
                    $buf->write($chunk);
                });
                $resp->on('end', function () use ($response, &$buf, $storage_dir, $cache_file) {
                    $response->end();

                    if (!is_dir(dirname($cache_file)))
                        mkdir(dirname($cache_file), 0777, true);
                    file_put_contents($cache_file, $buf->read());

                    $json_content = json_decode($buf->read(), true);
                    foreach ($json_content['packages'] as $pkg => $c) {
                        foreach ($c as $version => $c) {
                            $hash = $c['dist']['reference'];
                            $url = $c['dist']['url'];
                            $meta_path = $storage_dir . '/p/' . $pkg . '/' . $hash;
                            if (!is_dir(dirname($meta_path)))
                                mkdir(dirname($meta_path), 0777, true);
                            file_put_contents($meta_path, $url);
                        }
                    }

                    $buf->clear();
                });
                $resp->on('error', function (RuntimeException $e) use ($response, &$buf) {
                    $response->close();
                    $buf->clear();
                    throw $e;
                });
            });
            $request->end();
        } else {
            $response->end(file_get_contents($cache_file));
        }
    }
}

