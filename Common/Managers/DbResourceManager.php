<?php

namespace Common\Managers;

use App\Services\IAM\IAM;
use App\Services\IAM\Interfaces\IAMInterface;
use Common\Controllers\RootController;
use Common\DAOTrait;
use Common\Managers\Interfaces\ResourceManagerInterface;
use Common\Models\BaseDAO;
use Common\Models\BaseObject;
use Common\ResourceCRUDTrait;
use Core\Container\Container;
use Core\Database\Interfaces\DatabaseInterface;

class DbResourceManager extends RootController implements ResourceManagerInterface
{
    use ResourceCRUDTrait;
    use DAOTrait;

    private IAMInterface $IAM;
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db, IAM $iam, Container $container)
    {
        parent::__construct($container);
        $this->IAM = $iam;
        $this->db = $db;
    }

    public function readListBy(BaseObject $model, array $input, array $where): array
    {
        /** Read user input from Request object.
         * =============================================================================== */
        $query = $input['query'] ?? "";

        $sort = $input['sort'] ?? "";
        $sortBy = $input['sortBy'] ?? "";

        $limit = $input['limit'] ?? null;
        $offset = $input['offset'] ?? null;

        $archived = $input['archived'] ?? 0;

        $searchFields = json_decode($input['searchFields'] ?? "", 1);

        /** Gather information about model.
         * =============================================================================== */
        $tableName = $model->getTableName();
        $fields = $model->getTableFields();
        $additionalFields = $model->getAdditionalFields();
        $primaryFields = $model->getTableFields();

        $parentResourceKey = $this->getParentResourceKey();

        $keys = $model->getTableKeys();
        unset($keys[$parentResourceKey]);
        unset($keys["CompanyID"]);

        /** Gather information about model.
         * =============================================================================== */
        $joins = $this->map($keys, function ($key, $i, $k) use ($tableName) {
            $model = new $key();
            $joinTableName = $model->getTableName();
            $joinTablePK = $model->getPrimaryKey();

            return sprintf("LEFT JOIN %s as t%d ON t%d.%s=%s.%s", $joinTableName, $i + 1, $i + 1, $joinTablePK, $tableName, $k);
        });

        $tableAliasReplaceMap = [];
        $allAdditionalFieldsMap = array_merge($primaryFields, $additionalFields ?? []);
        $keysCopy = $keys;

        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) use ($keys, &$tableAliasReplaceMap, &$allAdditionalFieldsMap, &$keysCopy) {// Note that $tableAliasReplaceMap, and $allAdditionalFieldsMap must be passed as a reference
            /** @var BaseObject $model */
            $joinModel = new $key();

            $joinDescColumn = $joinModel->getDescColumn("t" . ($i + 1));
            $joinAlias = "t" . ($i + 1);
            $tableAliasReplaceMap[$joinModel->getTableName()] = $joinAlias;
            $joinAdditionalFields = $joinModel->getAdditionalFields();

            $select = "";
            $joinAdditionalFields = array_diff_key($joinAdditionalFields ?? [], $allAdditionalFieldsMap);
            if (!empty($joinAdditionalFields)) {
                $select .= "," . $this->fillAdditionalFieldsSelect($joinAdditionalFields, $joinModel, $keys, $tableAliasReplaceMap);
            }
            $allAdditionalFieldsMap = array_merge($allAdditionalFieldsMap ?? [], $joinAdditionalFields ?? []);

            $alias = array_search($key, $keysCopy);
            unset($keysCopy[$alias]);
            if (is_array($joinDescColumn)) {
                return implode(",", $joinDescColumn) . ', CONCAT(' . implode(",' ',", $joinDescColumn) . ') ' . str_replace("ID", "", $alias) . $select;
            }
            return sprintf("%s as %s", $joinDescColumn, str_replace("ID", "", $alias)) . $select;
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
            . (!empty($additionalFields) ? "," . $this->fillAdditionalFieldsSelect($additionalFields, $model, $keys, []) : "");

        $sql = (new BaseDAO($model))
            ->select($select)
            ->join($joins);
        $sql->setContainer($this->container);

        /** Add to WHERE clause for tables that are part of the multi tenant system (have CompanyID in a field list).
         * =============================================================================== */
        if (isset($fields["CompanyID"])) {
            $queryParam = sprintf("%s.CompanyID=%d", $tableName, $this->IAM->getCompanyID());
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

        /** Add special fields to be a part of WHERE clause.
         * =============================================================================== */
        if ($searchFields) {
            foreach ($searchFields as $key => $value) {
                if ($value) {
                    $searchField = sprintf("%s.%s", $tableName, $key);
                    if (!empty($additionalFields[$key])) {
                        $searchField = $this->fillPlaceholderTables($additionalFields[$key], $model, $keys, $tableAliasReplaceMap);
                    }
                    if (str_contains($value, ',')) {
                        $value = explode(',', $value);
                        $value = implode("','", $value);
                        $queryParam .= sprintf(" AND %s IN ('%s') ", $searchField, $value);
                    } else {
                        $queryParam .= sprintf(" AND %s = '%s' ", $searchField, $value);
                    }
                }
            }
        }

        /** Add exclude part of the WHERE clause.
         * =============================================================================== */
        if (!empty($ExcludeIDs)) {
            $queryParam .= sprintf(" AND %s.%s NOT IN (%s)", $tableName, $model->getPrimaryKey(), $ExcludeIDs);
        }

        /** Replace placeholders in the final WHERE string.
         * =============================================================================== */
        $queryParam = $this->fillPlaceholderTables($queryParam, $model, $keys, $tableAliasReplaceMap);

        /** Add WHERE part of the query.
         * =============================================================================== */
        foreach ($where as $k => $v) {
            $queryParam .= sprintf(" AND %s.%s=%d", $model->getTableName(), $k, $v);
        }

        if (!empty($queryParam)) {
            $sql->where($queryParam);
        }

        /** Add SORT part of the query.
         * =============================================================================== */
        if (!empty($sortBy) && $sort) {
            if (!empty($additionalFields[$sortBy])) {
                $additionalFields[$sortBy] = str_replace("{{" . $model->getTableName() . "}}", $model->getTableName(), $additionalFields[$sortBy]);
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

        return [
            'list' => $sql->getAll(),
            'count' => $sql->count(),
            'sql' => $sql->sql()
        ];
    }

    // Deprecated will be removed
    public function readList(BaseObject $model, $where = null)
    {
        return $this->handleResourceRead(
            $this->getDaoForObject(get_class($model)),
            false,
            null,
            $where
        );
    }

    public function findBy(BaseObject $model, string $key, string $value)
    {
        return $this->findWhere($model, [
            $key => $value
        ]);
    }

    public function findByID(BaseObject $model, int $id)
    {
        return $this->findWhere($model, [
            $model->getPrimaryKey() => $id
        ]);
    }

    public function findWhere(BaseObject $model, array $where)
    {
        /** Gather information about model.
         * =============================================================================== */
        $additionalFields = $model->getAdditionalFields();
        $tableName = $model->getTableName();

        $keys = $model->getTableKeys();
        unset($keys["CompanyID"]);

        /** Add JOIN part of query, and add to SELECT part according to JOIN tables list
         * =============================================================================== */
        $joins = $this->map($keys, function ($key, $i, $k) use ($tableName) {
            $model = new $key();
            $joinTableName = $model->getTableName();
            $joinTablePK = $model->getPrimaryKey();

            return sprintf("LEFT JOIN %s as t%d ON t%d.%s=%s.%s", $joinTableName, $i + 1, $i + 1, $joinTablePK, $tableName, $k);
        });

        $tableAliasReplaceMap = [];
        $allAdditionalFieldsMap = $additionalFields;

        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) use ($keys, &$tableAliasReplaceMap, &$allAdditionalFieldsMap, $model) {// Note that $tableAliasReplaceMap, and $allAdditionalFieldsMap must be passed as a reference
            /** @var BaseObject $joinModel */
            $joinModel = new $key();

            $joinTablePK = $joinModel->getPrimaryKey();
            $joinDescColumn = $joinModel->getDescColumn("t" . ($i + 1));
            $joinAlias = "t" . ($i + 1);
            $tableAliasReplaceMap[$joinModel->getTableName()] = $joinAlias;
            $joinAdditionalFields = $joinModel->getAdditionalFields();

            $select = "";
            $joinAdditionalFields = array_diff_key($joinAdditionalFields ?? [], $allAdditionalFieldsMap ?? []);
            if (!empty($joinAdditionalFields)) {
                $select .= "," . $this->fillAdditionalFieldsSelect($joinAdditionalFields, $model, $keys, $tableAliasReplaceMap);
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
        foreach ($keys as $tableOrder) {
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

        /** Add WHERE part of the query.
         * =============================================================================== */
        $queryParam = "1=1";
        foreach ($where as $k => $v) {
            // TODO Clean param
            $queryParam .= sprintf(" AND %s.%s=%s", $model->getTableName(), $k, is_string($v) ? "'$v'" : $v);
        }

        $sql = "SELECT " . $select . " FROM " . $model->getTableName() . (empty($joins) ? '' : " LEFT JOIN " . $joins) . sprintf(" WHERE %s", $queryParam);

        $result = $this->db->select($sql);

        if (isset($result[0])) {
            $result = $result[0];
        } else {
            return null;
        }

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

        return $result;
    }

    public function createFromData(BaseObject $model, $data = []): int
    {
        $data = $this->getRequestFieldsDataFromModel($model, $data);

        if (empty($data)) {
            return false;
        }

        $keys = array_keys($data);
        $columns = implode(',', $keys);
        $values = implode(',:', $keys);

        $sql = sprintf("INSERT INTO " . $model->getTableName() . " (%s) VALUES (:%s)", $columns, $values);

        if ($this->db->insert($sql, $data)) {
            return $this->db->lastInsertId();
        }

        return 0;
    }

    public function createBulkFromData(BaseObject $model, array $data = []): int
    {
        if (empty($data)) {
            return 0;
        }

        $i = 0;
        $insertData = [];
        $val = [];
        foreach ($data as $d) {
            $keys = array_keys($d);
            $columns = implode(',', $keys);
            $values = [];
            foreach ($keys as $k) {
                $key = "K" . ($i++);
                $values[] = ":".$key;
                $insertData[$key] = $d[$k];
            }
            $val[] = "(" . implode(',', $values) . ")";
            // $this->sqlEscape($val);
        }
        $val = implode(",", $val);
        $sql = sprintf("INSERT INTO " . $model->getTableName() . " (%s) VALUES %s", $columns, $val);

        return $this->db->insert($sql, $insertData);
    }

    public function updateFromData(BaseObject $model, int $id, array $data): int
    {
        $fields = $model->getTableFields();

        if (isset($fields['UpdatedByContactID'])) {
            $data['UpdatedByContactID'] = $this->IAM->getContactID();
        }
        if (isset($fields['CreateUpdateDate'])) {
            $data['CreateUpdateDate'] = $this->currentDateTime();
        }

        // TODO Check if company matches

        $data = $this->additionalDataProcess($data);

        $values = '';
        foreach ($data as $key => $value) {
            $values .= ($key . "=:" . $key . ",");
        }
        $values = rtrim($values, ',');

        $sql = sprintf("UPDATE %s SET %s WHERE %s=%s",
            $model->getTableName(), $values, $model->getPrimaryKey(), is_string($id) ? "'$id'" : $id);

        return $this->db->update($sql, $data);
    }

    public function deleteWhere(BaseObject $model, string $key, string $value): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s=%s',
            $model->getTableName(),
            $key,
            $value
        );

        return $this->db->delete($sql, []);
    }


    /**
     * This function will fill, and return, an array with values required to create/update given table model based on the passed array data.
     * Function will iterate through all fields from the given model, and will try to fill data unless it is
     * provided in the $defaults parameter. It will return empty array if model requires field that could not be provided.
     * @param BaseObject $model
     * @param array $inputData
     * @return array
     */
    private function getRequestFieldsDataFromModel(BaseObject $model, array $inputData): array
    {
        $data = [];

        foreach ($model->getTableFields() as $name => $type) {

            $value = null;

            // Take passed data firsts
            if (array_key_exists($name, $inputData)) {
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
            } else {
                if ($name === 'UpdatedByContactID') {
                    $value = $this->IAM->getContactID();
                } else if ($name === 'CreateUpdateDate') {
                    $value = $this->currentDateTime();
                } else if (
                    ($name === $model->getPrimaryKey())
                    || ($name === "Latitude")
                    || ($name === "Longitude")
                    || ($name === 'ArchivedDate')
                ) {
                    continue;
                } else if ($name === 'AutoToken') {
                    $value = $this->createRandomHash(date(DEFAULT_SQL_FORMAT));
                } else if ($name === 'CompanyID') {
                    $value = $this->IAM->getCompanyID();
                }
            }

            if (($value === null) && (strpos($type, "DEFAULT") !== false)) {
                $array = explode(" ", $type);
                $value = end($array);
            }

            $data[$name] = $value;

            if ((strpos($type, 'NULL') === false) && ($value === null)) {
                return []; // Exit function with no result if one of required fields is missing
            }
        }

        return $data;
    }

    private function fillPlaceholderTables(string $queryParam, BaseObject $model, array $keys, array $tableAliasReplaceMap): string
    {
        foreach ($keys as $tableOrder) {
            $m = new $tableOrder();
            $queryParam = str_replace("{{" . $m->getTableName() . "}}", !empty($tableAliasReplaceMap) ? $tableAliasReplaceMap[$m->getTableName()] : $m->getTableName(), $queryParam);
        }
        return str_replace("{{" . $model->getTableName() . "}}", $model->getTableName(), $queryParam);
    }

    private function fillAdditionalFieldsSelect(array $additionalFields, BaseObject $model, array $keys, array $tableAliasReplaceMap): string
    {
        return implode(", ", str_replace("\n", "", $this->map($additionalFields, function ($val, $i, $k) use ($keys, $model, $tableAliasReplaceMap) {
            return $k . "=" . $this->fillPlaceholderTables($val, $model, $keys, $tableAliasReplaceMap);
        })));
    }

    protected function filterVar($value, $filter, $option = null)
    {
        if ($filter == FILTER_SANITIZE_DATE) {
            return $this->sanitizeDate($value);
        } else if ($filter == FILTER_SANITIZE_NUMBER_FLOAT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_SANITIZE_NUMBER_INT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_VALIDATE_EMAIL) {
            $value = trim($value);
        }

        return filter_var($value, $filter, $option);
    }

    protected function sanitizeDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $format = 'Y-m-d H:i:s';
        $d = \DateTime::createFromFormat($format, $value);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return ($d && $d->format($format) === $value) ? $value : null;
    }

    protected function createRandomHash($value)
    {
        return substr(hash('sha512', $value . rand(1, 100)), 0, 24);
    }
}