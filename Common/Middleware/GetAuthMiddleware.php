<?php

namespace Common\Middleware;

use Core\Container\ContainerAware;
use Core\Http\Response;

class GetAuthMiddleware extends ContainerAware
{
    public function __invoke($next): Response
    {
        $token = $_GET['token'] ?? null;
        if ($token) {
            $_SERVER['HTTP_AUTHORIZATION'] = sprintf("Bearer %s", $token);
        }

        // Call next middleware
        return $next();
    }
}