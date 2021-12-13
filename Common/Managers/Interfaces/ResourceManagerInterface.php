<?php

namespace Common\Managers\Interfaces;

use Common\Models\BaseObject;

interface ResourceManagerInterface
{
    /**
     * Get list of objects with the related/joined data (based on $keys and $meta fields in passed BaseObject)
     * Queries for CompanyID automatically if present in a model and logged in user have CompanyID set (based on IAMInterface)
     * Queries for ArchivedDate being NULL if present in a model, if needed otherwise should be specified in a $inout parameter.
     * @param BaseObject $model
     * @param array $input - query, sort, sortBy, offset, limit, archived (if applicable table has ArchivedDate field), searchFields
     * @param array $where - key/value conditions
     * @return array - array ['list' => [], 'count'=> 0]
     */
    public function readListBy(BaseObject $model, array $input, array $where = []): array;

    /**
     * Same as readListBy but without $input parameter.
     * @param BaseObject $model
     * @param array $where
     * @return array
     */
    public function readListWhere(BaseObject $model, array $where): array;

    /**
     * Returns single object with related/joined data according to passed $where parameters.
     * Queries for CompanyID automatically if present in a model and logged in user have CompanyID set (based on IAMInterface)
     * @param BaseObject $model
     * @param array $where
     * @return mixed
     */
    public function findWhere(BaseObject $model, array $where);

    /**
     * Same as findWhere but with single custom parameters.
     * @param BaseObject $model
     * @param string $key
     * @param string $value
     * @return mixed
     */
    public function findBy(BaseObject $model, string $key, string $value);

    /**
     * Same as findWhere but with primary key parameter only.
     * @param BaseObject $model
     * @param int $id
     * @return mixed
     */
    public function findByID(BaseObject $model, int $id);

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
     * Updates object in the database based on the passed $where params
     * Only fields that are passed in the $data array will be updated.
     * System fields like UpdatedByContactID, CreateUpdateDate, and CompanyID will be auto populated.
     * @return int (If updates i successful value greater than 0 is returned)
     */
    public function updateFromDataWhere(BaseObject $model, array $where, array $data): int;

    /**
    * Same as updateFromDataWhere but with primary key value
     * @param BaseObject $model
     * @param int $id
     * @param array $data
     * @return int (If updates i successful value greater than 0 is returned)
     */
    public function updateFromData(BaseObject $model, int $id, array $data): int;

    /**
     * !! Deprecated will be replaced with deleteWhere(BaseObject $model, array $where)
     *
     * Deletes object(s) from the database based on the passed parameters.
     * This performs hard delete.
     * @param BaseObject $model
     * @param string $key
     * @param string $value
     * @return int
     */
    public function deleteWhere(BaseObject $model, string $key, string $value): int;
}