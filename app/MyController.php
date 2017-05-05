<?php

namespace App;


use React\Http\Request;
use React\Http\Response;
use React\HttpClient\Response as ClientResponse;

class MyController
{
    public function action(Request $request, Response $response, $parameters)
    {
        $loop = $GLOBALS['LOOP'];
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $dnsResolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);
        $factory = new \React\HttpClient\Factory();
        $client = $factory->create($loop, $dnsResolver);

        $client = new \GuzzleHttp\Client();
        $res = $client->request('GET', 'https://packagist.org/packages.json');
        $json_content = json_decode($res->getBody(), true);
        //$json_content['providers-url']='/package/%package%$%hash%.json'
        $json_content['mirrors'] = [
            [
                "dist-url" => "https://pkgist.b0.upaiyun.com/file/%package%/%reference%.%type%",
                "preferred" => true
            ],
        ];
        $json_content['notify'] = 'https://packagist.org/downloads/%package%';
        $json_content['notify-batch'] = 'https://packagist.org/downloads/';
        $json_content['search'] = 'https://packagist.org/search.json?q=%query%&type=%type%';

        $response->writeHead(200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=60'
        ]);
        $response->end(json_encode($json_content, JSON_PRETTY_PRINT));
    }
}
