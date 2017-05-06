<?php

namespace App;

use App\Controller\DistFile;
use App\Controller\Package;
use App\Controller\Provider;
use App\Controller\RootServer;
use DI\ContainerBuilder;
use DI\Definition\ObjectDefinition;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Application
{

    private $routes;
    private $config;
    private $container;

    public function __construct(LoopInterface $loop)
    {
        $builder = new ContainerBuilder();

        $this->config = new Config(__DIR__ . '/../config/app.yml');

        $this->loop = $loop;

        $this->container = $builder->build();
        $this->container->set(LoopInterface::class, $loop);
        $this->container->set(Config::class, $this->config);

        $this->routes = new RouteCollection();

        $route = new Route('/packages.json', array('_controller' => RootServer::class));
        $this->routes->add('RootServer', $route);

        $route = new Route(
            '/p/provider-{subPath}',
            array('_controller' => Provider::class),
            array('subPath' => '.+')
        );
        $this->routes->add('Provider', $route);

        $route = new Route(
            '/p/{subPath}',
            array('_controller' => Package::class),
            array('subPath' => '.+')
        );
        $this->routes->add('Package', $route);

        $route = new Route(
            '/file/{subPath}',
            array('_controller' => DistFile::class),
            array('subPath' => '.+')
        );
        $this->routes->add('DistFile', $route);
    }

    public function onRequest(Request $request, Response $response)
    {
        $context = new RequestContext();

        $matcher = new UrlMatcher($this->routes, $context);

        try {
            $parameters = $matcher->match($request->getPath());

            /** @var Controller $controller */
            $controller = $this->container->get($parameters['_controller']);
            $controller->action($request,$response,$parameters);
        } catch (ResourceNotFoundException $e) {
            $response->writeHead(404);
            $response->end();
        }
    }

    public function getConfig()
    {
        return $this->config;
    }
}
