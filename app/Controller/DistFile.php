<?php

namespace App\Controller;


use App\Config;
use App\Controller;
use App\Lib\Buffer;
use React\Dns\Resolver\Factory as DnsFactory;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use React\HttpClient\Client;
use React\HttpClient\Factory as ClientFactory;
use React\HttpClient\Request as ClientRequest;
use React\HttpClient\Response as ClientResponse;

class DistFile implements Controller
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
        $cache_file = $this->config->storage_dir . $request->getPath();

        if (!file_exists($cache_file)) {
            $comps1 = explode('/', $request->getPath());
            $comps2 = explode('.', $comps1[4]);
            $url_file = $this->config->storage_dir . '/p/' . $comps1[2] . '/' . $comps1[3] . '/' . $comps2[0];
            if (!is_file($url_file)) {
                $response->writeHead(404);
                return;
            }
            $origin_url = file_get_contents($url_file);

            $resolverFactory = new DnsFactory();
            $resolver = $resolverFactory->create('8.8.8.8', $this->loop);
            $factory = new ClientFactory();
            $client = $factory->create($this->loop, $resolver);

            $request = $client->request('GET', $origin_url);

            self::processRealData(
                $request,
                function (ClientResponse $resp) use ($response) {
                    $response->writeHead(200, ['Cache-Control' => 'public, max-age=86400']);
                },
                function ($chunk) use ($response) {
                    if (strlen($chunk) > 0)
                        $response->write($chunk);
                },
                function ($buf) use ($response, $cache_file) {
                    $response->end();
                    if (!is_dir(dirname($cache_file)))
                        mkdir(dirname($cache_file), 0777, true);
                    file_put_contents($cache_file, $buf);
                },
                $client
            );

            $request->end();
        } else {
            $response->writeHead(200, ['Cache-Control' => 'public, max-age=86400']);
            $response->end(file_get_contents($cache_file));
        }
    }

    static public function processRealData(ClientRequest $req, $startCallback, $callback, $endCallback, Client $client)
    {
        $processRedirect = function (ClientResponse $resp) use (&$client, &$processRedirect, $startCallback, $callback, $endCallback) {
            if (in_array($resp->getCode(), [301, 302, 304])) {
                $req = $client->request('GET', $resp->getHeaders()['Location']);
                $req->on('response', $processRedirect);
                $req->end();
            } else {
                $buf = new Buffer();
                $startCallback($resp);
                $resp->on('data', function ($chunk) use ($callback, &$buf) {
                    $buf->write($chunk);
                    $callback($chunk);
                });
                $resp->on('end', function () use ($endCallback, &$buf) {
                    $endCallback($buf->read());
                });
            }
        };
        $req->on('response', $processRedirect);
    }
}
