<?php

namespace KCoreCommon\Controllers\Common;

use App\Models\BaseObject;
use App\Models\DAO\BaseDAO;

/**
 * Trait ResourceCRUDTrait
 * @package App\Controllers\Common
 * !! Should be used with classes that inherit BaseController
 * Assumes that there is user property in the class with $user['Contact']['ContactID'] and $user['Contact']['CompanyID'] data filled.
 *
 */
trait ResourceCRUDTrait
{
    use GeoLocationTrait;
    use FunctionalTrait;

    /** Set of functions used for automatic CRUD operations on a single model based on HTTP Request object
     * ======================================================================== */

    public function handleResourceRead(BaseDAO $resourceDao, $output = true, $overrideParentKey = null)
    {
        $user = $this->user;

        $CompanyID = $user['Contact']['CompanyID'];

        $query = $this->get('query', FILTER_SANITIZE_STRING);

        $sort = $this->get('sort', FILTER_SANITIZE_STRING);
        $sortBy = $this->get('sortBy', FILTER_SANITIZE_STRING);

        $limit = $this->get('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->get('offset', FILTER_SANITIZE_NUMBER_INT);

        $archived = $this->get('archived', FILTER_SANITIZE_NUMBER_INT);

        $format = $this->get('format', FILTER_SANITIZE_STRING);

        $ExcludeIDs = $this->get('ExcludeIDs', FILTER_SANITIZE_STRING);
        $searchFields = json_decode($this->get('searchFields'), 1);

        $model = $resourceDao->getModel();
        $tableName = $model->getTableName();
        $fields = $model->getTableFields();

        $parentResourceKey = $this->getParentResourceKey();
        $keys = $model->getTableKeys();
        unset($keys[$parentResourceKey]);
        unset($keys["CompanyID"]);

        $joins = $this->map($keys, function ($key, $i, $k) use ($tableName) {
            $model = new $key();
            $joinTableName = $model->getTableName();
            $joinTablePK = $model->getPrimaryKey();
            return sprintf("LEFT JOIN %s as t%d ON t%d.%s=%s.%s", $joinTableName, $i + 1, $i + 1, $joinTablePK, $tableName, $k);
        });
        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) {
            /** @var BaseObject $model */
            $model = new $key();

            $joinTablePK = $model->getPrimaryKey();
            $descColumn = $model->getDescColumn("t" . ($i + 1));

            if (is_array($descColumn)) {
                return implode(",", $descColumn) . ', CONCAT(' . implode(",' ',", $descColumn) . ') ' . str_replace("ID", "", $joinTablePK);
            }
            return sprintf("%s as %s", $descColumn, str_replace("ID", "", $joinTablePK));
        }));

        if (empty($joins)) {
            $joins = null;
        } else {
            $joins = substr(implode(" ", $joins), 9);
        }

        $select = $tableName . '.*' . (!empty($joinsSelects) ? "," . $joinsSelects : "");

        $sql = $resourceDao
            ->select($select)
            ->join($joins);

        if (!$parentResourceKey) {
            $queryParam = sprintf("%s.CompanyID=%d", $tableName, $CompanyID);
        } else {
            $queryParam = "1=1 ";
        }

        if (isset($fields['ArchivedDate'])) {
            if (!$archived) {
                $queryParam .= sprintf(" AND %s.ArchivedDate IS NULL", $tableName);
            }
        }

        if (!empty($query)) {
            $searchableCol = $model->getSearchableColumns();

            if (isset($searchableCol[0])) {
                $queryParam .= (empty($queryParam)) ? " ( " : " AND ( ";

                foreach ($searchableCol as $key => $value) {

                    $chunks = explode(' ', $query);

                    foreach ($chunks as $chunk) {
                        $queryParam .= sprintf("(%s.%s LIKE '%%%s%%') OR ", $tableName, $value, $chunk);
                    }
                    $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " OR ";
                }
                $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " ) ";
            }
        }

        if ($overrideParentKey) {
            $parentResourceKey = $overrideParentKey;
        } else {
            $parentResourceKey = $this->getParentResourceKey();
        }

        if ($parentResourceKey) {
            $id = $this->get('id', FILTER_SANITIZE_NUMBER_INT);
            $queryParam .= sprintf(" AND %s.%s=%d", $tableName, $parentResourceKey, $id);
        }

        if ($searchFields) {
            foreach ($searchFields as $key => $value) {
                if ($value) {
                    $queryParam .= sprintf(" AND %s.%s = %s ", $tableName, $key, $value);
                }
            }
        }

        if (!empty($ExcludeIDs)) {
            $queryParam .= sprintf(" AND %s.%s NOT IN (%s)", $tableName, $model->getPrimaryKey(), $ExcludeIDs);
        }

        if (!empty($queryParam)) {
            $sql->where($queryParam);
        }

        if (!empty($sortBy) && $sort) {
            $sql->orderBy($sortBy);
            $sql->order($sort);
        }

        if (($limit !== null) && ($offset !== null)) {
            $sql->limit($limit);
            $sql->start($offset);
        }

        if ($output) {
            if ($format === "EMAIL" || $format === "EXCEL") {
                $report = (new AbstractReports($this->getContainer(), "export", $format));

                $sql->limit($limit);// Check for max limit when performance is tested
                $sql->start($offset);

                $model = $resourceDao->getModel();

                return $report->generateExel($model, $sql->getAll());
            }

            return $this->json([
                'status' => 0,
                'message' => 'OK',
                'data' => [
                    'list' => $sql->getAll(),
                    'count' => $sql->count(),
                    'sql' => $sql->sql()
                ]
            ]);
        }

        return [
            'list' => $sql->getAll(),
            'count' => $sql->count(),
            'sql' => $sql->sql()
        ];
    }

    public function handleSingleResourceRead(BaseDAO $resourceDao)
    {
        $model = $resourceDao->getModel();
        $tableName = $model->getTableName();

        $parentResourceKey = $this->getParentResourceKey();
        $keys = $this->filter($model->getTableKeys(), function ($key, $i, $k) use ($parentResourceKey) {
            return ($k !== $parentResourceKey) && ($k !== "CompanyID");
        });

        $joins = $this->map($keys, function ($key) use ($tableName) {
            $model = new $key();
            $joinTableName = $model->getTableName();
            $joinTablePK = $model->getPrimaryKey();
            return sprintf("LEFT JOIN %s ON %s.%s=%s.%s", $joinTableName, $joinTableName, $joinTablePK, $tableName, $joinTablePK);
        });
        $joinsSelects = implode(", ", $this->map($keys, function ($key) {
            $model = new $key();
            $joinTablePK = $model->getPrimaryKey();
            $descColumn = $model->getDescColumn();
            return sprintf("%s as %s", $descColumn, str_replace("ID", "", $joinTablePK));
        }));

        if (empty($joins)) {
            $joins = null;
        } else {
            $joins = substr(implode(" ", $joins), 9);
        }

        $select = $tableName . '.*' . (!empty($joinsSelects) ? "," . $joinsSelects : "");

        $sql = $resourceDao
            ->select($select)
            ->join($joins);

        $parentKeyID = $this->getParentResourceKey();
        $id = $this->get('id', FILTER_SANITIZE_NUMBER_INT);

        $sql->where([$parentKeyID => $id]);

        return $this->json([
            'status' => 0,
            'message' => 'OK',
            'data' => $sql->getOne()
        ]);
    }

    public function handleResourceCreate(BaseDAO $resourceDao, $defaults = [])
    {
        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsFromModel($model, false, $defaults);

        if (empty($data)) {
            return false;
        }

        $data = $this->additionalDataProcess($data);

        return $resourceDao->insert($data);
    }

    /**
     * @param BaseDAO $resourceDao
     * @param array $defaults Will overide values that are in HTTP request
     * @param int $primaryKey Will be used only if no primary key is provided in the request
     * @return false|int
     */
    public function handleResourceUpdate(BaseDAO $resourceDao, array $defaults = [], int $primaryKey = 0)
    {
        $model = $resourceDao->getModel();

        $ArchivedDate = $this->data('ArchivedDate', FILTER_SANITIZE_NUMBER_INT);

        if ($ArchivedDate) {
            $data = [
                'ArchivedDate' => null
            ];
        } else {
            $data = $this->getRequestFieldsFromModel($model, true, $defaults);
        }

        if (empty($data)) {
            return false;
        }

        $data = $this->additionalDataProcess($data);

        if (!empty($this->data($model->getPrimaryKey(), FILTER_SANITIZE_NUMBER_INT))) {
            $primaryKey = ($this->data($model->getPrimaryKey(), FILTER_SANITIZE_NUMBER_INT));
        }
        // Fallback, keep for now
        if (!$primaryKey) {
            $primaryKey = $this->data('id', FILTER_SANITIZE_NUMBER_INT);
        }

        return $resourceDao->update($primaryKey, $data);
    }

    public function handleBulkResourceUpdate(BaseDAO $resourceDao)
    {
        $user = $this->user;

        $currentDate = $this->currentDateTime();

        $ids = $this->data('IDs');
        $sanitizedIDs = $this->map($ids, function ($it) {
            return $this->filterVar($it, FILTER_SANITIZE_NUMBER_INT);
        });

        $flds = $this->data('Fields');

        $fldsToUpdate = array_merge([
            'UpdatedByContactID' => $user['Contact']['ContactID'],
            'CreateUpdateDate' => $currentDate
        ], $flds);

        return $resourceDao->bulkUpdate($sanitizedIDs, $fldsToUpdate);
    }

    public function handleResourceDelete(BaseDAO $resourceDao): int
    {
        $model = $resourceDao->getModel();
        $idFieldName = $model->getPrimaryKey();
        $idFieldValue = $this->get($idFieldName, FILTER_SANITIZE_NUMBER_INT);
        if (!$idFieldValue) {
            $idFieldValue = $this->get('id', FILTER_SANITIZE_NUMBER_INT);
        }
        return $resourceDao->updateWhere(isset($model->getTableFields()['CompanyID']) ? [
            $idFieldName => $idFieldValue,
            "CompanyID" => $this->user['Contact']['CompanyID']
        ] : [$idFieldName => $idFieldValue], [
            'UpdatedByContactID' => $this->user['Contact']['ContactID'],
            'CreateUpdateDate' => $this->currentDateTime(),
            'ArchivedDate' => $this->currentDateTime()
        ]);
    }

    public function handleResourceHardDelete(BaseDAO $resourceDao): int
    {
        $model = $resourceDao->getModel();
        $idFieldName = $model->getPrimaryKey();
        $idFieldValue = $this->get($idFieldName, FILTER_SANITIZE_NUMBER_INT);
        if (!$idFieldValue) {
            $idFieldValue = $this->get('id', FILTER_SANITIZE_NUMBER_INT);
        }
        return $resourceDao->deleteWhere(isset($model->getTableFields()['CompanyID']) ? [
            $idFieldName => $idFieldValue,
            "CompanyID" => $this->user['Contact']['CompanyID']
        ] : [$idFieldName => $idFieldValue]);
    }

    public function handleResourceRestore(BaseDAO $resourceDao): int
    {
        $model = $resourceDao->getModel();
        $idFieldName = $model->getPrimaryKey();
        $idFieldValue = $this->data($idFieldName, FILTER_SANITIZE_NUMBER_INT);
        if (!$idFieldValue) {
            $idFieldValue = $this->data('id', FILTER_SANITIZE_NUMBER_INT);
        }
        return $resourceDao->update($idFieldValue, [
            'UpdatedByContactID' => $this->user['Contact']['ContactID'],
            'CreateUpdateDate' => $this->currentDateTime(),
            'ArchivedDate' => null
        ]);
    }

    /** Set of functions used for automatic CRUD operations on a single model based on provided array of data
     * ======================================================================== */

    private function handleResourceCreateFromData(BaseDAO $resourceDao, array $data, array $defaults = []): int
    {
        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsDataFromModel($model, $data, false, $defaults);

        if (empty($data)) {
            return 0;
        }

        $data = $this->additionalDataProcess($data);

        return $resourceDao->insert($data);
    }

    public function handleResourceUpdateFromData(BaseDAO $resourceDao, array $data): int
    {
        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsDataFromModel($model, $data, true);

        $data = $this->additionalDataProcess($data);

        return $resourceDao->update($this->data($model->getPrimaryKey(), FILTER_SANITIZE_NUMBER_INT), $data);
    }

    public function handleBulkResourceCreateFromData(BaseDAO $resourceDao, $data, array $defaults = []): int
    {
        if (empty($data)) {
            return 0;
        }

        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsArrayFromModel($model, $data, false, $defaults);

        return $resourceDao->bulkInsert($data);
    }

    public function handleBulkResourceUpdateFromData(BaseDAO $resourceDao): int
    {
        $user = $this->user;

        $currentDate = $this->currentDateTime();

        $ids = $this->data('IDs');
        $sanitizedIDs = $this->map($ids, function ($it) {
            return $this->filterVar($it, FILTER_SANITIZE_NUMBER_INT);
        });

        $flds = $this->data('Fields');

        $fldsToUpdate = array_merge([
            'UpdatedByContactID' => $user['Contact']['ContactID'],
            'CreateUpdateDate' => $currentDate
        ], $flds);

        return $resourceDao->bulkUpdate($sanitizedIDs, $fldsToUpdate);
    }

    /** Set of functions used for automatic extractions of data from request, or an array based on the model.
     * ======================================================================== */

    /**
     * This function will fill, and return, an array with values required to create/update given table model based on the HTTP Request.
     * Function will iterate through all fields from the given model, and will try to fill data from the HTTP request. If any of model fields are provided
     * in the $defaults parameter they will have their values filled from $defaults instead.
     * It will return empty array if model requires field that could not be provided.
     * For update based queries $isUpdate parameter should be set to true in order to provide parent reference field value.
     * @param BaseObject $model
     * @param false $isUpdate
     * @param array $defaults
     * @return array
     */
    private function getRequestFieldsFromModel(BaseObject $model, bool $isUpdate = false, array $defaults = []): array
    {
        $parentResourceKey = $this->getParentResourceKey();

        $user = $this->user;
        $currentDateTime = $this->currentDateTime();

        $data = [];
        foreach ($model->getTableFields() as $name => $type) {
            // Take defaults firsts
            if (array_key_exists($name, $defaults)) {
                $data[$name] = $defaults[$name];
                continue;
            }

            if ($name === 'UpdatedByContactID') {
                $value = $user['Contact']['ContactID'];
            } else if ($name === 'CreateUpdateDate') {
                $value = $currentDateTime;
            } else if (
                ($name === $model->getPrimaryKey())
                || ($name === "Latitude")
                || ($name === "Longitude")
                || ($name === 'ArchivedDate')
            ) {
                continue;
            } else if ($name === $parentResourceKey) {
                if (!$isUpdate) {
                    $value = $this->data("id", FILTER_SANITIZE_NUMBER_INT);
                    if (!$value) {
                        $value = $this->data($parentResourceKey, FILTER_SANITIZE_NUMBER_INT);
                    }
                } else {
                    continue;
                }
            } else if ($name === 'AutoToken') {
                $value = $this->createRandomHash(date(DEFAULT_SQL_FORMAT));
            } else if ($name === 'CompanyID') {
                $value = $this->user['Contact']['CompanyID'];
            } else if (!array_key_exists($name, $this->container['data'])) {
                continue;
            } else {
                // Fill from HTTP request data
                if (strpos($type, 'int') === 0) {
                    $value = $this->data($name, FILTER_SANITIZE_NUMBER_INT);
                } else if (strpos($type, 'decimal') === 0) {
                    $value = $this->data($name, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                } else if (strpos($type, 'datetime') === 0) {
                    $value = $this->data($name, FILTER_SANITIZE_DATE);
                } else if (($name === "ShippingHours")
                    || ($name === "ReceivingHours")) {
                    $value = json_encode($this->data($name));
                } else {
                    $value = $this->data($name, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
                }
            }

            if (($value === null) && (strpos($type, "DEFAULT") !== false)) {
                $array = explode(" ", $type);
                $value = end($array);
            }

            $data[$name] = $value;

            if ((strpos($type, 'NULL') === false) && ($value === null)) {
                $this->logger->info("MISSING_FIELD: " . $name . " = " . $value, [
                    'table' => $model->getTableName()
                ]);
                return []; // Exit function with no result if one of required fields is missing
            }
        }
        return $data;
    }

    /**
     * This function will fill, and return, an array with values required to create/update given table model based on the passed array data.
     * Function will iterate through all fields from the given model, and will try to fill data unless it is
     * provided in the $defaults parameter. It will return empty array if model requires field that could not be provided.
     * @param BaseObject $model
     * @param array $inputData
     * @param false $isUpdate
     * @param array $defaults
     * @return array
     */
    private function getRequestFieldsDataFromModel(BaseObject $model, array $inputData, bool $isUpdate = false, array $defaults = []): array
    {
        $parentResourceKey = $this->getParentResourceKey();

        $user = $this->user;
        $currentDateTime = $this->currentDateTime();

        $data = [];
        foreach ($model->getTableFields() as $name => $type) {
            // Take defaults firsts
            if (array_key_exists($name, $defaults)) {
                $data[$name] = $defaults[$name];
                continue;
            }

            if ($name === 'UpdatedByContactID') {
                $value = $user['Contact']['ContactID'];
            } else if ($name === 'CreateUpdateDate') {
                $value = $currentDateTime;
            } else if (
                ($name === $model->getPrimaryKey())
                || ($name === "Latitude")
                || ($name === "Longitude")
                || ($name === 'ArchivedDate')
            ) {
                continue;
            } else if ($name === $parentResourceKey) {
                if (!$isUpdate) {
                    $value = $this->filterVar("id", FILTER_SANITIZE_NUMBER_INT);
                    if (!$value) {
                        $value = $this->data($parentResourceKey, FILTER_SANITIZE_NUMBER_INT);
                    }
                } else {
                    continue;
                }
            } else if ($name === 'AutoToken') {
                $value = $this->createRandomHash(date(DEFAULT_SQL_FORMAT));
            } else if ($name === 'CompanyID') {
                $value = $this->user['Contact']['CompanyID'];
            } else if (!array_key_exists($name, $inputData)) {
                continue;
            } else {
                // Fill from passed data
                if (strpos($type, 'int') === 0) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_NUMBER_INT);
                } else if (strpos($type, 'decimal') === 0) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                } else if (strpos($type, 'datetime') === 0) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_DATE);
                } else {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
                }
            }

            if (($value === null) && (strpos($type, "DEFAULT") !== false)) {
                $array = explode(" ", $type);
                $value = end($array);
            }

            $data[$name] = $value;

            if ((strpos($type, 'NULL') === false) && ($value === null)) {
                $this->logger->info("MISSING_FIELD: " . $name . " = " . $value, [
                    'table' => $model->getTableName()
                ]);
                return []; // Exit function with no result if one of required fields is missing
            }
        }

        return $data;
    }

    /**
     * Works same as getRequestFieldsDataFromModel but operates on an array of data thus creates 2D array as output.
     * In most cases should be used for bulk inserts, or updates.
     * @param BaseObject $model
     * @param array $data
     * @param false $isUpdate
     * @param array $defaults
     * @return array
     */
    private function getRequestFieldsArrayFromModel(BaseObject $model, array $data = [], bool $isUpdate = false, array $defaults = []): array
    {
        $parentResourceKey = $this->getParentResourceKey();

        $user = $this->user;
        $currentDateTime = $this->currentDateTime();

        return $this->map($data, function ($it) use ($defaults, $isUpdate, $parentResourceKey, $currentDateTime, $user, $model) {
            $data = [];
            foreach ($model->getTableFields() as $name => $type) {
                // Take defaults firsts
                if (array_key_exists($name, $defaults)) {
                    $data[$name] = $defaults[$name];
                    continue;
                }

                if ($name === 'UpdatedByContactID') {
                    $value = $user['Contact']['ContactID'];
                } else if ($name === 'CreateUpdateDate') {
                    $value = $currentDateTime;
                } else if (
                    ($name === $model->getPrimaryKey())
                    || ($name === "Latitude")
                    || ($name === "Longitude")
                    || ($name === 'ArchivedDate')
                ) {
                    continue;
                } else if ($name === $parentResourceKey) {
                    if (!$isUpdate) {
                        $value = $this->filterVar("id", FILTER_SANITIZE_NUMBER_INT);
                        if (!$value) {
                            $value = $this->data($parentResourceKey, FILTER_SANITIZE_NUMBER_INT);
                        }
                    } else {
                        continue;
                    }
                } else if ($name === 'AutoToken') {
                    $value = $this->createRandomHash(date(DEFAULT_SQL_FORMAT));
                } else if ($name === 'CompanyID') {
                    $value = $this->user['Contact']['CompanyID'];
                } else if (strpos($type, 'int') === 0) {
                    $value = $this->filterVar($it[$name], FILTER_SANITIZE_NUMBER_INT);
                } else {
                    // Fill from passed data
                    if (strpos($type, 'int') === 0) {
                        $value = $this->filterVar($it[$name], FILTER_SANITIZE_NUMBER_INT);
                    } else if (strpos($type, 'decimal') === 0) {
                        $value = $this->filterVar($it[$name], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    } else if (strpos($type, 'datetime') === 0) {
                        $value = $this->filterVar($it[$name], FILTER_SANITIZE_DATE);
                    } else {
                        $value = $this->filterVar($it[$name], FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
                    }
                }

                if (($value === null) && (strpos($type, "DEFAULT") !== false)) {
                    $array = explode(" ", $type);
                    $value = end($array);
                }

                $data[$name] = $value;


                if ((strpos($type, 'NULL') === false) && ($value === null)) {
                    $this->logger->info("MISSING_FIELD: " . $name . " = " . $value, [
                        'table' => $model->getTableName()
                    ]);
                    return []; // Exit function with no result if one of required fields is missing
                }
            }

            return $data;
        });
    }

    /** Utility functions
     * ======================================================================== */

    private function additionalDataProcess($data)
    {
        $this->logger->info(1, ['trade' => $data]);
        if (isset($data['AddressName']) && !array_key_exists('CompanyName', $data)) {
            $lnlt = $this->getLatLonFromDatabase();
            if (empty($lnlt['Latitude'])) {
                $lnlt = $this->getLatLonFromAddressLine();
            }
            $data['Latitude'] = $lnlt['lat'];
            $data['Longitude'] = $lnlt['lng'];
        }

        return $data;
    }

    private function getParentResourceKey()
    {
        return null;
    }
}