<?php

namespace Common\Hooks;

use Core\Container\ContainerAware;
use Core\Http\Response;
use Exception;
use Monolog\Logger;

/**
 * Class InternalErrorHook
 * @property Logger logger
 */
class InternalErrorHook extends ContainerAware
{
    /**
     * @param Exception $e
     * @return Response
     */
    public function __invoke(Exception $e): Response
    {
        $this->logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);

        return (new Response())
            ->setStatusCode(500)
            ->setBody(json_encode(['']));
    }
}