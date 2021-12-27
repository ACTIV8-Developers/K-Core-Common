<?php

namespace Common;

use Core\Container\Container;
use Core\Container\ContainerAware;
use Monolog\Processor\ProcessorInterface;

class MonologUserProcessor extends ContainerAware implements ProcessorInterface
{
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function __invoke(array $record): array
    {
        if (isset($this->container['user'])) {
            $record['extra'] = $this->appendExtraFields($record['extra']);
        }

        return $record;
    }

    private function appendExtraFields(array $extra): array
    {
        $extra['ContactID'] = $this->container['user']['ContactID'];
        $extra['Email'] = $this->container['user']['user_id'];
        return $extra;
    }
}