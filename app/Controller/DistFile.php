<?php

namespace App\Controller;


use App\Config;
use App\Controller;
use App\Lib\Buffer;
use GuzzleHttp\Client;
use React\Dns\Resolver\Factory as DnsFactory;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use React\HttpClient\Client as HttpClient;
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

    public function processWithoutProviders($pkgname, $reference)
    {
        $storage_dir = $this->config->storage_dir;
        $ret = false;

        $client = new Client();
        $res = $client->request('GET', 'https://packagist.org/p/' . $pkgname . '.json');
        $json_content = json_decode($res->getBody(), true);
        foreach ($json_content['packages'] as $pkg => $c) {
            foreach ($c as $version => $c) {
                $hash = $c['dist']['reference'];
                if ($hash === $reference)
                    $ret = true;
                $url = $c['dist']['url'];
                $meta_path = $storage_dir . '/p/' . $pkg . '/' . $hash;
                if (!is_dir(dirname($meta_path)))
                    mkdir(dirname($meta_path), 0777, true);
                file_put_contents($meta_path, $url);
            }
        }
        return $ret;
    }

    public function action(Request $request, Response $response, $parameters)
    {
        $comps1 = explode('/', $request->getPath());
        $comps2 = explode('.', $comps1[4]);
        $url_file = $this->config->storage_dir . '/p/' . $comps1[2] . '/' . $comps1[3] . '/' . $comps2[0];
        if (
            !is_file($url_file)
            && !$this->processWithoutProviders($comps1[2] . '/' . $comps1[3], $comps2[0])
        ) {
            $response->writeHead(404);
            $response->end();
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
            function ($buf) use ($response) {
                $response->end();
            },
            $client
        );

        $request->end();
    }

    static public function processRealData(ClientRequest $req, $startCallback, $callback, $endCallback, HttpClient $client)
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
