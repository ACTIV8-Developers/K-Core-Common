<?php

namespace Common\Managers\Interfaces;

use Common\Models\BaseObject;

interface ResourceManagerInterface
{
    public function readList(BaseObject $model, $where = null);

    public function readListBy(BaseObject $model, string $key, string $value);

    public function findBy(BaseObject $model, string $key, string $value);

    public function findByID(BaseObject $model, int $id);

    public function findWhere(BaseObject $model, string $where);

    public function createFromData(BaseObject $model, array $data): int;

    public function updateFromData(BaseObject $model, int $id, array $data): int;

    public function deleteWhere(BaseObject $model, string $key, string $value): int;
}