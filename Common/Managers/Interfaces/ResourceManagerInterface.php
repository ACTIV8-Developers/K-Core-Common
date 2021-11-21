<?php

namespace Common\Managers\Interfaces;

use Common\Models\BaseObject;

interface ResourceManagerInterface
{
    /**
     * Get list of objects with the related/joined data.
     * Queries for CompanyID automatically if present in a model.
     * @param BaseObject $model
     * @param array $input - query, sort, sortBy, offset, limit, archived (if applicable table has ArchivedDate field), searchFields
     * @param array $where - key/value conditions
     * @return array
     */
    public function readListBy(BaseObject $model, array $input, array $where): array;

    public function readList(BaseObject $model, $where = null);

    public function findBy(BaseObject $model, string $key, string $value);

    public function findByID(BaseObject $model, int $id);

    public function findWhere(BaseObject $model, array $where);

    /**
     * Creates object in the database based on the passed array values.
     * Only fields that are passed in the $data array will be used for creation.
     * System fields like UpdatedByContactID, CreateUpdateDate, and CompanyID will be auto populated.
     * @param BaseObject $model
     * @param array $data
     * @return int (Value of the primary key of newly created DB object, 0 denotes creation failed)
     */
    public function createFromData(BaseObject $model, array $data): int;

    /**
     * Same as createFromData but for multiple inserts.
     * @param BaseObject $model
     * @param array $data
     * @return int
     */
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