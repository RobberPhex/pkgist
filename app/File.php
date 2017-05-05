<?php

namespace App;

use App\Lib\Buffer;
use React\Http\Request;
use React\Http\Response;
use React;

use React\HttpClient\Factory as ClientFactory;
use React\HttpClient\Response as ClientResponse;
use React\HttpClient\Request as ClientRequest;
use React\HttpClient\Client;

class File
{
    public function action(Request $request, Response $response, $parameters)
    {
        $cache_path = $GLOBALS['STOR_DIR'] . $request->getPath();

        if (!file_exists($cache_path)) {
            $comps = explode('/', $request->getPath());
            $json_content = json_decode(file_get_contents(
                $GLOBALS['STOR_DIR'] . '/p/' . $comps[2] . '/' . $comps[3] . '.json'
            ), true);
            $comps = explode('.', $comps[4]);
            $origin_url = $json_content[$comps[0]];

            echo $origin_url;
            echo PHP_EOL;

            $loop = $GLOBALS['LOOP'];
            $resolverFactory = new React\Dns\Resolver\Factory();
            $resolver = $resolverFactory->create('8.8.8.8', $loop);
            $factory = new ClientFactory();
            $client = $factory->create($loop, $resolver);

            $request = $client->request('GET', $origin_url);

            self::processRealData(
                $request,
                function (ClientResponse $resp) use ($response) {
                    $headers = $resp->getHeaders();
                    $response->writeHead(200, [
                        'Content-Length' => $headers['Content-Length'],
                        'Cache-Control' => 'public, max-age=86400'
                    ]);
                },
                function ($chunk) use ($response) {
                    $response->write($chunk);
                },
                function ($buf) use ($response, $cache_path) {
                    $response->end();
                    echo $cache_path;
                    echo PHP_EOL;
                    if (!is_dir(dirname($cache_path)))
                        mkdir(dirname($cache_path), 0777, true);
                    file_put_contents($cache_path, $buf);
                },
                $client
            );

            $request->end();
        } else {
            $response->writeHead(200, ['Cache-Control' => 'public, max-age=86400']);
            $response->end(file_get_contents($cache_path));
        }
    }

    static public function processRealData(ClientRequest $req, $startCallback, $callback, $endCallback, Client $client)
    {
        $processRedirect = null;
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
