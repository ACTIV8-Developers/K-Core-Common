<?php

namespace Common\Managers\Interfaces;

interface ImagePathManagerInterface
{
    public function read(string $resource, int $id): array;

    public function create(string $resource, int $id, string $name, string $path): int;
}