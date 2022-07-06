<?php

namespace Common\Middleware;

use Core\Container\Container;
use Core\Container\ContainerAware;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Class CustomCorsMiddleware
 * @property Request request
 */
class CustomCorsMiddleware extends ContainerAware
{
    private string $headers = "";

    /**
     * LoggingMiddleware constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container, string $headers = "Origin, Authorization, Content-Type, Accept-Ranges")
    {
        $this->container = $container;
        $this->headers = $headers;
    }

    /**
     * @param callable $next
     * @return Response
     */
    public function __invoke(callable $next): Response
    {
        if ($this->request->isOptions()) {
            return (new Response())
                ->setHeader('Access-Control-Allow-Origin', "*")
                ->setHeader('Access-Control-Allow-Methods', "POST, GET, DELETE, PUT, PATCH, OPTIONS")
                ->setHeader('Access-Control-Allow-Headers', $this->headers)
                ->setHeader('Access-Control-Expose-Headers', $this->headers)
                ->setHeader('Access-Control-Max-Age', "0")
                ->setHeader('Content-Length', "0")
                ->setHeader('Content-Type', "text/plain");
        }

        $response = $next();

        if ($response instanceof Response) {
            $response->setHeader('Access-Control-Allow-Origin', '*');
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}