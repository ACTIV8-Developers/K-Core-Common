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

    /**
     * Creates object in the database based on the passed array values.
     * Only fields that are passed in the $data array will be used for creation.
     * System fields like UpdatedByContactID, CreateUpdateDate, and CompanyID will be auto populated.
     * @param BaseObject $model
     * @param array $data
     * @return int (Value of the primary key of newly created DB object, 0 denotes creation failed)
     */
    public function createFromData(BaseObject $model, array $data): int;

    public function createBulkFromData(BaseObject $model, array $data): int;

    /**
     * Updates object in the database based on the passed primary key value.
     * Only fields that are passed in the $data array will be updated.
     * System fields like UpdatedByContactID, CreateUpdateDate, and CompanyID will be auto populated.
     * @param BaseObject $model
     * @param int $id
     * @param array $data
     * @return int (If updates i successful value greater than 0 is returned)
     */
    public function updateFromData(BaseObject $model, int $id, array $data): int;

    public function deleteWhere(BaseObject $model, string $key, string $value): int;
}