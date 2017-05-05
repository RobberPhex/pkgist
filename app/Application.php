<?php

namespace App;

use React\EventLoop\LoopInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Application
{

    private $routes;
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $GLOBALS['LOOP'] = $loop;
        $GLOBALS['STOR_DIR'] = __DIR__ . '/../storage';

        $this->routes = new RouteCollection();

        $route = new Route('/packages.json', array('_controller' => '\App\MyController'));
        $this->routes->add('route_name1', $route);

        $route = new Route(
            '/p/provider-{subPath}',
            array('_controller' => '\App\SubProvider'),
            array('subPath' => '.+')
        );
        $this->routes->add('route_name2', $route);

        $route = new Route(
            '/p/{subPath}',
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

    public function onRequest(\React\Http\Request $request, \React\Http\Response $response)
    {
        $context = new RequestContext();

        $matcher = new UrlMatcher($this->routes, $context);

        try {
            $parameters = $matcher->match($request->getPath());

            call_user_func([$parameters['_controller'], 'action'], $request, $response, $parameters);
        } catch (ResourceNotFoundException $e) {
            $response->writeHead(404);
            $response->end();
        }
    }
}
