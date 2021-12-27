<?php

namespace Common\Controllers;

use Common\Models\BaseObject;

interface ResourceCRUDHandlerInterface
{
    // Read
    public function handleResourceRead(BaseObject $resource): array;
    public function handleSingleResourceRead(BaseObject $resource): array;

    // Create
    public function handleResourceCreate(BaseObject $resource, $defaults = []): int;
    public function handleResourceCreateFromData(BaseObject $resource, array $data, array $defaults = []): int;
    public function handleBulkResourceCreateFromData(BaseObject $resource, $data, array $defaults = []): int;

    // Update
    public function handleResourceUpdate(BaseObject $resource, array $defaults = [], int $primaryKey = 0): int;
    public function handleBulkResourceUpdate(BaseObject $resource): int;
    public function handleResourceUpdateFromData(BaseObject $resource, array $data): int;
    public function handleBulkResourceUpdateFromData(BaseObject $resource): int;

    // Delete
    public function handleResourceDelete(BaseObject $resource): int;
    public function handleResourceHardDelete(BaseObject $resource): int;
}