<?php

namespace Common;

use Common\Models\BaseObject;
use Common\Models\BaseDAO;
use Core\Http\Interfaces\ResponseInterface;
use Core\Http\Response;

/**
 * Magical Trait that solves all the problems - ResourceCRUDTrait
 *
 * !! Should be used with classes that inherit RootController
 * Assumes that there is user property in the class with $user['Contact']['ContactID'] and $user['Contact']['CompanyID'] data filled.
 */
trait ResourceCRUDTrait
{
    use GeoLocationTrait;
    use FunctionalTrait;

    /** Set of functions used for automatic READ operations on a single model based on HTTP Request object
     * ========================================================================
     * @param BaseDAO $resourceDao
     * @param bool $output
     * @param null $overrideParentKey
     * @param null $where
     * @param null $overrideID
     * @return array|ResponseInterface|Response
     */

    public function handleResourceRead(BaseDAO $resourceDao, bool $output = true, $overrideParentKey = null, $where = null, $overrideID = null)
    {
        /** Read user input from Request object.
         * =============================================================================== */
        $user = $this->user;

        $CompanyID = $user['Contact']['CompanyID'];

        $id = $this->get('id', FILTER_SANITIZE_NUMBER_INT);

        $query = $this->get('query', FILTER_SANITIZE_STRING);

        $sort = $this->get('sort', FILTER_SANITIZE_STRING);
        $sortBy = $this->get('sortBy', FILTER_SANITIZE_STRING);

        $limit = $this->get('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->get('offset', FILTER_SANITIZE_NUMBER_INT);

        $archived = $this->get('archived', FILTER_SANITIZE_NUMBER_INT);

        $format = $this->get('format', FILTER_SANITIZE_STRING);

        $ExcludeIDs = $this->get('ExcludeIDs', FILTER_SANITIZE_STRING);

        $searchFields = json_decode($this->get('searchFields'), 1);

        /** Gather information about model.
         * =============================================================================== */
        $model = $resourceDao->getModel();
        $tableName = $model->getTableName();
        $fields = $model->getTableFields();
        $additionalFields = $model->getAdditionalFields();

        $parentResourceKey = $this->getParentResourceKey();

        $keys = $model->getTableKeys();
        unset($keys[$parentResourceKey]);
        unset($keys["CompanyID"]);

        /** Add JOIN part of query, and add to SELECT part according to JOIN tables list
         * =============================================================================== */
        $joins = $this->map($keys, function ($key, $i, $k) use ($resourceDao, $tableName) {
            $model = new $key();
            $joinTableName = $model->getTableName();
            $joinTablePK = $model->getPrimaryKey();

            return sprintf("LEFT JOIN %s as t%d ON t%d.%s=%s.%s", $joinTableName, $i + 1, $i + 1, $joinTablePK, $tableName, $k);
        });

        $tableAliasReplaceMap = [];
        $allAdditionalFieldsMap = $additionalFields;

        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) use ($keys, &$tableAliasReplaceMap, &$allAdditionalFieldsMap) {// Note that $tableAliasReplaceMap, and $allAdditionalFieldsMap must be passed as a reference
            /** @var BaseObject $model */
            $joinModel = new $key();

            $joinTablePK = $joinModel->getPrimaryKey();
            $joinDescColumn = $joinModel->getDescColumn("t" . ($i + 1));
            $joinAlias = "t" . ($i + 1);
            $tableAliasReplaceMap[$joinModel->getTableName()] = $joinAlias;
            $joinAdditionalFields = $joinModel->getAdditionalFields();

            $select = "";
            $joinAdditionalFields = array_diff_key($joinAdditionalFields ?? [], $allAdditionalFieldsMap ?? []);
            if (!empty($joinAdditionalFields)) {
                $select .= "," . $this->fillAdditionalFieldsSelect($joinAdditionalFields, $joinModel, $keys, $tableAliasReplaceMap);
            }
            $allAdditionalFieldsMap = array_merge($allAdditionalFieldsMap ?? [], $joinAdditionalFields ?? []);

            if (is_array($joinDescColumn)) {
                return implode(",", $joinDescColumn) . ', CONCAT(' . implode(",' ',", $joinDescColumn) . ') ' . str_replace("ID", "", $joinTablePK) . $select;
            }
            return sprintf("%s as %s", $joinDescColumn, str_replace("ID", "", $joinTablePK)) . $select;
        }));

        if (empty($joins)) {
            $joins = null;
        } else {
            $joins = substr(implode(" ", $joins), 9);
        }

        /** Add SELECT part of the query.
         * =============================================================================== */
        foreach ($additionalFields ?? [] as $k => $v) {
            if ($k == $v) {
                unset($additionalFields[$k]);
            }
        }

        $select = $tableName . '.*'
            . (!empty($joinsSelects) ? "," . $joinsSelects : "")
            . (!empty($additionalFields) ? "," . $this->fillAdditionalFieldsSelect($additionalFields, $model, $keys, $tableAliasReplaceMap) : "");

        $select = str_replace("[[key]]", $id, $select);

        $sql = $resourceDao
            ->select($select)
            ->join($joins);

        $global = "";
        if ($model->getGlobalFields() && !empty($model->getGlobalFields())) {
            $global = implode(",", $model->getGlobalFields());
        }
        $global = str_replace("[[key]]", $id, $global);

        /** Add to WHERE clause for tables that are part of the multi tenant system (have CompanyID in a field list).
         * =============================================================================== */
        if (isset($fields["CompanyID"])) {
            $queryParam = sprintf("%s.CompanyID=%d", $tableName, $CompanyID);
        } else {
            $queryParam = "1=1";
        }

        /** Add to WHERE clause for tables that support soft delete feature (have ArchivedDate in a field list).
         * =============================================================================== */
        if (isset($fields['ArchivedDate'])) {
            if (!$archived) {
                $queryParam .= sprintf(" AND %s.ArchivedDate IS NULL", $tableName);
            }
        }

        /** Add standard text query fields to WHERE clause.
         * =============================================================================== */
        if (!empty($query)) {
            $searchableCol = $model->getSearchableColumns();

            if (isset($searchableCol[0])) {
                $queryParam .= (empty($queryParam)) ? " ( " : " AND ( ";

                foreach ($searchableCol as $value) {

                    $chunks = explode(' ', $query);
                    foreach ($chunks as $chunk) {
                        $queryParam .= sprintf("(%s.%s LIKE '%%%s%%') OR ", $tableName, $value, $chunk);
                    }
                    $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " OR ";
                }
                $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " ) ";
            }

            if (!empty($keys)) {
                $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " OR ";
                $i = 1;
                foreach ($keys as $key) {
                    $joinModel = new $key();
                    $searchableCol = $joinModel->getSearchableColumns();
                    if (isset($searchableCol[0])) {
                        foreach ($searchableCol as $value) {
                            $chunks = explode(' ', $query);
                            foreach ($chunks as $chunk) {
                                $queryParam .= sprintf(" (%s.%s LIKE '%%%s%%') OR ", "t" . $i, $value, $chunk);
                            }
                            $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " OR ";
                        }
                    }
                    $i++;
                }
                $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " ) ";
            }
        }

        /** If table is part of the parent model feature add it to WHERE clause.
         * =============================================================================== */
        if ($overrideParentKey !== null) {
            $parentResourceKey = $overrideParentKey;
        }

        if ($parentResourceKey) {
            if ($overrideID) {
                $id = $overrideID;
            }
            if ($id) {
                $queryParam .= sprintf(" AND %s.%s=%d", $tableName, $parentResourceKey, $id);
            }
        }

        /** Add special fields to be a part of WHERE clause.
         * =============================================================================== */
        if ($searchFields) {
            foreach ($searchFields as $key => $value) {
                if ($value) {
                    $searchField = sprintf("%s.%s", $tableName, $key);
                    if (!empty($additionalFields[$key])) {
                        $searchField = $this->fillPlaceholderTables($additionalFields[$key], $model, $keys, $tableAliasReplaceMap);
                    }
                    $queryParam .= sprintf(" AND %s = '%s' ", $searchField, $value);
                }
            }
        }

        /** Add exclude part of the WHERE clause.
         * =============================================================================== */
        if (!empty($ExcludeIDs)) {
            $queryParam .= sprintf(" AND %s.%s NOT IN (%s)", $tableName, $model->getPrimaryKey(), $ExcludeIDs);
        }

        /** Add custom user passed part of WHERE clause.
         * =============================================================================== */
        if ($where) {
            $queryParam .= " AND " . $where;
        }

        /** Replace placeholders in the final WHERE string.
         * =============================================================================== */
        $queryParam = $this->fillPlaceholderTables($queryParam, $model, $keys, $tableAliasReplaceMap);

        /** Add WHERE part of the query.
         * =============================================================================== */
        if (!empty($queryParam)) {
            $sql->where($queryParam);
        }

        /** Add SORT part of the query.
         * =============================================================================== */
        if (!empty($sortBy) && $sort) {
            if (!empty($additionalFields[$sortBy])) {
                $sortBy = $this->fillPlaceholderTables($additionalFields[$sortBy], $model, $keys, $tableAliasReplaceMap);
            }
            $sql->orderBy($sortBy);
            $sql->order($sort);
        }

        /** Add pagination part of the query.
         * =============================================================================== */
        if (($limit !== null) && ($offset !== null)) {
            $sql->limit($limit);
            $sql->start($offset);
        }

        $rt = [
            'list' => $sql->getAll(),
            'count' => $sql->count(),
            'sql' => $sql->sql()
        ];

        /** Output as EXCEL file
         * =============================================================================== */
        if ($output && ($format === "EMAIL" || $format === "EXCEL")) {
            $report = (new AbstractReports($this->getContainer(), "export", $format));

            $sql->limit($limit);// Check for max limit when performance is tested
            $sql->start($offset);

            $model = $resourceDao->getModel();

            return $report->generateExel($model, $sql->getAll());
        }

        /** Output as JSON response
         * =============================================================================== */
        if ($output) {
            if ($global != "") {
                $gl = $this->db->select("SELECT TOP 1 " . $global . " FROM " . $resourceDao->getModel()->getTableName(), []);
                foreach ($gl[0] ?? [] as $k => $v) {
                    $rt[$k] = $v;
                }
            }
            return $this->json([
                'status' => 0,
                'message' => 'OK',
                'data' => $rt
            ]);
        }

        /** Output as PHP array
         * =============================================================================== */
        if ($global != "") {
            $gl = $this->db->query("SELECT TOP 1 " . $global . " FROM " . $resourceDao->getModel()->getTableName());
            foreach ($gl->fetchAll()[0] ?? [] as $k => $v) {
                $rt[$k] = $v;
            }
        }
        return $rt;
    }

    public function handleSingleResourceRead(BaseDAO $resourceDao, $output = true, $overrideParentKey = null)
    {
        /** Read user input from Request object.
         * =============================================================================== */
        $id = $this->get('id', FILTER_SANITIZE_NUMBER_INT);

        /** Gather information about model.
         * =============================================================================== */
        $model = $resourceDao->getModel();
        $additionalFields = $model->getAdditionalFields();
        $tableName = $model->getTableName();

        $parentResourceKey = $this->getParentResourceKey();

        $keys = $model->getTableKeys();
        unset($keys[$parentResourceKey]);
        unset($keys["CompanyID"]);

        /** Add JOIN part of query, and add to SELECT part according to JOIN tables list
         * =============================================================================== */
        $joins = $this->map($keys, function ($key, $i, $k) use ($resourceDao, $tableName) {
            $model = new $key();
            $joinTableName = $model->getTableName();
            $joinTablePK = $model->getPrimaryKey();

            return sprintf("LEFT JOIN %s as t%d ON t%d.%s=%s.%s", $joinTableName, $i + 1, $i + 1, $joinTablePK, $tableName, $k);
        });

        $tableAliasReplaceMap = [];
        $allAdditionalFieldsMap = $additionalFields;

        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) use ($keys, &$tableAliasReplaceMap, &$allAdditionalFieldsMap) {// Note that $tableAliasReplaceMap, and $allAdditionalFieldsMap must be passed as a reference
            /** @var BaseObject $model */
            $joinModel = new $key();

            $joinTablePK = $joinModel->getPrimaryKey();
            $joinDescColumn = $joinModel->getDescColumn("t" . ($i + 1));
            $joinAlias = "t" . ($i + 1);
            $tableAliasReplaceMap[$joinModel->getTableName()] = $joinAlias;
            $joinAdditionalFields = $joinModel->getAdditionalFields();

            $select = "";
            $joinAdditionalFields = array_diff_key($joinAdditionalFields ?? [], $allAdditionalFieldsMap ?? []);
            if (!empty($joinAdditionalFields)) {
                $select .= "," . $this->fillAdditionalFieldsSelect($joinAdditionalFields, $joinModel, $keys, $tableAliasReplaceMap);
            }
            $allAdditionalFieldsMap = array_merge($allAdditionalFieldsMap ?? [], $joinAdditionalFields ?? []);

            if (is_array($joinDescColumn)) {
                return implode(",", $joinDescColumn) . ', CONCAT(' . implode(",' ',", $joinDescColumn) . ') ' . str_replace("ID", "", $joinTablePK) . $select;
            }
            return sprintf("%s as %s", $joinDescColumn, str_replace("ID", "", $joinTablePK)) . $select;
        }));

        if (empty($joins)) {
            $joins = null;
        } else {
            $joins = substr(implode(" ", $joins), 9);
        }

        /** Add SELECT part of the query.
         * =============================================================================== */
        $select = $tableName . '.*'
            . (!empty($joinsSelects) ? "," . $joinsSelects : "")
            . (!empty($additionalFields) ? "," . implode(", ", str_replace("\n", "", $this->map($additionalFields, function ($val, $i, $k) {
                    return $k . "=" . $val;
                }))) : "");

        $c = 1;
        foreach ($keys as $t => $tableOrder) {
            $m = new $tableOrder();
            $select = str_replace("{{" . $m->getTableName() . "}}", "t" . $c, $select);
            $c++;
        }

        if ($model->getAdditionalFields()) {
            $select .= (!empty($model->getAdditionalFields()) ? "," . implode(", ", str_replace("\n", "", $this->map($model->getAdditionalFields(), function ($val, $i, $k) {
                    return $k . "=" . $val;
                }))) : "");
        }

        $select = str_replace("{{" . $model->getTableName() . "}}", $model->getTableName(), $select);
        $sql = $resourceDao
            ->select($select)
            ->join($joins);

        /** If table is part of the parent model feature add it to WHERE clause.
         * =============================================================================== */
        if ($overrideParentKey !== null) {
            $parentResourceKey = $overrideParentKey;
        }

        /** Add WHERE part of the query.
         * =============================================================================== */
        $sql->where(sprintf("%s.%s = %d", $tableName, $parentResourceKey, $id));

        $result = $sql->getOne();

        /** Add NESTED data. (TABLES key in meta data)
         * =============================================================================== */
        foreach ($model->getTables() ?? [] as $table => $primary) {
            $m = new $table();
            $result[str_replace("tbl_", "", $m->getTableName())] =
                $this->db->query("SELECT * FROM " . $m->getTableName() . " WHERE " . $primary . "=" . $result[$primary])->fetchAll();
        }

        foreach ($keys as $key => $value) {
            $model = new $value();
            if (!empty($model->getTables())) {
                foreach ($model->getTables() as $table => $primary) {
                    $model = new $table();
                    if ($result[$key])
                        if (empty($result[str_replace("tbl_", "", $model->getTableName())]))
                            $result[str_replace("tbl_", "", $model->getTableName())] =
                                $this->db->query("SELECT * FROM " . $model->getTableName() . " WHERE " . $model->getTableName() . "." . $model->getPrimaryKey() . "=" . $result[$key])->fetchAll();
                }
            }
        }

        /** Output as JSON response
         * =============================================================================== */
        if ($output) {
            return $this->json([
                'status' => 0,
                'message' => 'OK',
                'data' => $result,
                'sql' => $sql->sql()
            ]);
        }

        /** Output as PHP array
         * =============================================================================== */
        return $result;
    }

    /** Set of functions used for automatic CREATE, UPDATE, DELETE operations on a single model based on HTTP Request object
     * ======================================================================== */

    public function handleResourceCreate(BaseDAO $resourceDao, $defaults = [])
    {
        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsFromModel($model, false, $defaults);

        if (empty($data)) {
            return false;
        }

        if (!empty($model->getValidate()) && !$this->validate($model->getValidate(), $data, $resourceDao)) {
            return false;
        }

        return $resourceDao->insert($this->additionalDataProcess($data));
    }

    /**
     * @param BaseDAO $resourceDao
     * @param array $defaults Will override values that are in HTTP request
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

        $where = [$model->getPrimaryKey() => $primaryKey];

        if (isset($model->getTableFields()['CompanyID'])) {
            $where['CompanyID'] = $this->user['Contact']['CompanyID'];
        }

        return $resourceDao->updateWhere($where, $data);
    }

    public function handleBulkResourceUpdate(BaseDAO $resourceDao): int
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

    /** Set of functions used for automatic CREATE, UPDATE, DELETE operations on a single model based on provided array of data
     * ======================================================================== */

    public function handleResourceCreateFromData(BaseDAO $resourceDao, array $data, array $defaults = []): int
    {
        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsDataFromModel($model, $data, false, $defaults);

        if (empty($data)) {
            return 0;
        }

        $data = $this->additionalDataProcess(array_merge($data, $defaults));

        return $resourceDao->insert($data);
    }

    public function handleBulkResourceCreateFromData(BaseDAO $resourceDao, $data, array $defaults = []): int
    {
        if (empty($data)) {
            return 0;
        }

        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsArrayFromModel($model, $data, false, $defaults);

        $resourceDao->bulkInsert($data);
        return $this->db->lastInsertId();
    }

    public function handleResourceUpdateFromData(BaseDAO $resourceDao, array $data): int
    {
        $model = $resourceDao->getModel();

        $data = $this->getRequestFieldsDataFromModel($model, $data, true);

        $data = $this->additionalDataProcess($data);

        return $resourceDao->update($this->data($model->getPrimaryKey(), FILTER_SANITIZE_NUMBER_INT), $data);
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
    protected function getRequestFieldsFromModel(BaseObject $model, bool $isUpdate = false, array $defaults = []): array
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
    protected function getRequestFieldsArrayFromModel(BaseObject $model, array $data = [], bool $isUpdate = false, array $defaults = []): array
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

    private function validate($validate, $data, BaseDAO $resourceDao): bool
    {
        foreach ($validate as $val) {
            $i = 0;
            foreach ($val[0] as $v) {
                if (strpos($v, '{{') !== false) {
                    foreach ($this->getBetween($v, "{{", "}}") as $value) {
                        $val[0][$i] = str_replace("{{" . $value . "}}", "'" . $data[$value] . "'", $val[0][$i]);
                    }
                }
                $i++;
            }
            if ($val[1] == 'sql') {
                $data = $this->db->query($val[0][0])->fetchAll();
                if ($data[0]['var'] == 0) {
                    return false;
                }
            } else if ($val[1] == 'last') {
                if ($resourceDao->select("*")->where($val[0][0])->orderBy($resourceDao->getModel()->getPrimaryKey())->order("DESC")->limit(1)->start(0)->getOne() == null)
                    return false;
            } else if ($val[1] == 'first') {
                if ($resourceDao->select("*")->where($val[0][0])->orderBy($resourceDao->getModel()->getPrimaryKey())->order("ASC")->limit(1)->start(0)->getOne() == null)
                    return false;
            } else if ($val[1] == 'data') {
                for ($i = -1; $i < count($val[0]); $i += 3) {
                    $first = $val[0][$i + 1];
                    $sign = $val[0][$i + 2];
                    $second = $val[0][$i + 3];

                    if ($sign == "<") {
                        if ($first >= $second) {
                            return false;
                        }
                    } else if ($sign == ">") {
                        if ($first <= $second) {
                            return false;
                        }
                    } else if ($sign == "=") {
                        if ($first != $second) {
                            return false;
                        }
                    } else if ($sign == "!=") {
                        if ($first == $second) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    private function getBetween($content, $start, $end): array
    {
        $n = explode($start, $content);
        $result = array();
        foreach ($n as $val) {
            $pos = strpos($val, $end);
            if ($pos !== false) {
                $result[] = substr($val, 0, $pos);
            }
        }
        return $result;
    }

    protected function additionalDataProcess($data)
    {
        if (isset($data['AddressName']) && !array_key_exists('CompanyName', $data) && array_key_exists('Latitude', $data)) {
            $lnlt = $this->getLatLonFromAddressLine($data);
            // TODO revert read from DB
            $data['Latitude'] = $lnlt['lat'];
            $data['Longitude'] = $lnlt['lng'];
        }

        return $data;
    }

    public function getRadiusQuery(BaseDAO $table, BaseDAO $tableLocation = null): string
    {
        $SearchLatitude = $this->get('SearchLatitude', FILTER_SANITIZE_STRING);
        $SearchLongitude = $this->get('SearchLongitude', FILTER_SANITIZE_STRING);
        $SearchRadius = $this->get('SearchRadius', FILTER_SANITIZE_NUMBER_INT);

        if ($SearchLatitude && $SearchLongitude && $SearchRadius) {
            $field = $table->getModel()->getPrimaryKey();
            $table = $table->getModel()->getTableName();
            if ($tableLocation) {
                $tableLocation = $tableLocation->getModel()->getTableName();
                $where = sprintf($table . "." . $field . " IN (SELECT TOP 1 TruckID FROM " . $tableLocation . " WHERE " . $tableLocation . "." . $field . "=" . $table . "." . $field . " AND SQUARE((69.1 * (CAST(Longitude AS decimal) - %.6f) * cos(%.6f / 57.3))) + SQUARE((69.1 * (CAST(Latitude AS decimal) - %.6f))) < (%.6f * %.6f) ORDER BY " . $tableLocation . ".TruckLocationID DESC)", $SearchLongitude, $SearchLatitude, $SearchLatitude, $SearchRadius, $SearchRadius);
            } else {
                $where = sprintf("SQUARE((69.1 * (" . $table . ".Longitude - %.6f) * cos(%.6f / 57.3))) + SQUARE((69.1 * (" . $table . ".Latitude - %.6f))) < (%.6f * %.6f)", $SearchLongitude, $SearchLatitude, $SearchLatitude, $SearchRadius, $SearchRadius);
            }
        } else {
            return "1=1";
        }
        return $where;
    }

    private function fillPlaceholderTables(string $queryParam, BaseObject $model, array $keys, array $tableAliasReplaceMap): string
    {
        foreach ($keys as $tableOrder) {
            $m = new $tableOrder();
            $queryParam = str_replace("{{" . $m->getTableName() . "}}", $tableAliasReplaceMap[$m->getTableName()], $queryParam);
        }
        return str_replace("{{" . $model->getTableName() . "}}", $model->getTableName(), $queryParam);
    }

    private function fillAdditionalFieldsSelect(array $additionalFields, BaseObject $model, array $keys, array $tableAliasReplaceMap): string
    {
        return implode(", ", str_replace("\n", "", $this->map($additionalFields, function ($val, $i, $k) use ($keys, $model, $tableAliasReplaceMap) {
            return $k . "=" . $this->fillPlaceholderTables($val, $model, $keys, $tableAliasReplaceMap);
        })));
    }

    protected function getParentResourceKey()
    {
        return null;
    }
}