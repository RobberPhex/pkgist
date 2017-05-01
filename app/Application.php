<?php

namespace App;

use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Application
{

    private $server;

    public function __construct()
    {
        $GLOBALS['STOR_DIR'] = __DIR__ . '/../storage';

        $http = new \Swoole\Http\Server("0.0.0.0", 9501);
        $http->set(['buffer_output_size' => 12000000]);

        $this->server = $http;
        $this->routes = new RouteCollection();
    }

    public function onWorkerStart($serv, $worker_id)
    {
        $route = new Route('/packages.json', array('_controller' => '\App\MyController'));
        $this->routes->add('route_name1', $route);

        $route = new Route(
            '/provider/{subPath}',
            array('_controller' => '\App\SubProvider'),
            array('subPath' => '.+')
        );
        $this->routes->add('route_name2', $route);

        $route = new Route(
            '/package/{subPath}',
            array('_controller' => '\App\Package'),
            array('subPath' => '.+')
        );
        $this->routes->add('route_name3', $route);

        $route = new Route(
            '/file/{subPath}',
            array('_controller' => '\App\File'),
            array('subPath' => '.+')
        );
        $this->routes->add('route_name4', $route);
    }

    public function onRequest($request, $response)
    {
        $context = new RequestContext();

        $matcher = new UrlMatcher($this->routes, $context);

        $parameters = $matcher->match($request->server['request_uri']);

        call_user_func([$parameters['_controller'], 'action'], $request, $response, $parameters);
    }

    public function run()
    {

        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);

        $this->server->on('request', [$this, 'onRequest']);

        $this->server->start();

        return 0;
    }
}
