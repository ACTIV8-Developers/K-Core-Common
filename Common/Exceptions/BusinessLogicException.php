<?php

namespace Common\Exceptions;

class BusinessLogicException extends \Exception
{
    private array $data = [];

    public function __construct($message = "", $data = []) {
        parent::__construct($message);
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
}