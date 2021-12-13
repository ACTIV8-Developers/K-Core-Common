<?php

namespace Common\Middleware;

use Core\Container\Container;
use Core\Container\ContainerAware;
use Core\Http\Response;
use OAuth2\Request;
use OAuth2\Server;

/**
 * Class ScopedAuthMiddleware
 * @property Server oauth
 * @property \Core\Http\Request request
 */
class ScopedAuthMiddleware extends ContainerAware
{
    private ?string $scope;

    /**
     * ScopedAuthMiddleware constructor.
     *
     * @param Container $container
     * @param ?string $scope
     */
    public function __construct(Container $container, ?string $scope = null)
    {
        $this->container = $container;
        $this->scope = $scope;
    }

    /**
     * @param callable $next
     * @return callable|Response
     */
    public function __invoke(callable $next): Response
    {
        if (!$this->oauth->verifyResourceRequest(Request::createFromGlobals(), null, $this->scope)) {
            $tokenResponse = $this->oauth->getResponse();
            $response = new Response();
            foreach ($tokenResponse->getHttpHeaders() as $name => $header) {
                $response->setHeader($name, $header);
            }
            $response->setHeader('Content-Type', 'application/json');
            return $response
                ->setStatusCode($tokenResponse->getStatusCode())
                ->setBody($tokenResponse->getResponseBody());
        }

        // Get user data
        $this->container['user'] = $this->oauth->getAccessTokenData(Request::createFromGlobals());

        // Call next middleware
        return $next();
    }
}