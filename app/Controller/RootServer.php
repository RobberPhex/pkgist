<?php

namespace App\Controller;


use App\Config;
use App\Controller;
use GuzzleHttp\Client;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;

class RootServer implements Controller
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
        $client = new Client();
        $res = $client->request('GET', 'https://packagist.org/packages.json');
        $json_content = json_decode($res->getBody(), true);

        $json_content['mirrors'] = [
            [
                "dist-url" => $this->config->base_url . "file/%package%/%reference%.%type%",
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
