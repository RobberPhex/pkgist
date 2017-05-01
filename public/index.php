<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

$GLOBALS['STOR_DIR']=__DIR__.'/../storage';

$http = new Swoole\Http\Server("0.0.0.0", 9501);
$http->set(['buffer_output_size'=>12000000]);
$routes = new RouteCollection();
$http->on('WorkerStart', function ($serv, $worker_id) use ($routes) {

    $route = new Route('/packages.json', array('_controller' => '\App\MyController'));
    $routes->add('route_name1', $route);

    $route = new Route(
        '/provider/{subPath}',
        array('_controller' => '\App\SubProvider'),
        array('subPath' => '.+')
    );
    $routes->add('route_name2', $route);

    $route = new Route(
        '/package/{subPath}',
        array('_controller' => '\App\Package'),
        array('subPath' => '.+')
    );
    $routes->add('route_name3', $route);

    $route = new Route(
        '/file/{subPath}',
        array('_controller' => '\App\File'),
        array('subPath' => '.+')
    );
    $routes->add('route_name4', $route);
});

$http->on('request', function ($request, $response) use ($routes) {

    $context = new RequestContext();

    $matcher = new UrlMatcher($routes, $context);

    $parameters = $matcher->match($request->server['request_uri']);
    //$response->end(print_r($parameters,true));

    call_user_func([$parameters['_controller'],'action'],$request,$response,$parameters);
});

$http->start();