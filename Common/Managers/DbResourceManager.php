<?php

namespace Common\Managers;

use Common\DAOTrait;
use Common\Managers\Interfaces\ResourceManagerInterface;
use Common\Models\BaseDAO;
use Common\Models\BaseObject;
use Common\ResourceCRUDTrait;
use Common\Services\IAM\Interfaces\IAMInterface;
use Core\Container\Container;
use Core\Database\Interfaces\DatabaseInterface;
use Exception;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

class DbResourceManager implements ResourceManagerInterface
{
    use ResourceCRUDTrait;
    use DAOTrait;

    private IAMInterface $IAM;
    private DatabaseInterface $db;
    private ContainerInterface $container;

    public function __construct(DatabaseInterface $db, IAMInterface $iam, Container $container)
    {
        $this->IAM = $iam;
        $this->db = $db;
        $this->container = $container;
    }

    public function readListBy(BaseObject $model, array $input, array $where = [], $noLock = false): array
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

        $ExcludeIDs = $input['ExcludeIDs'] ?? [];

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
        $joins = $this->map($keys, function ($key, $i, $k) use ($tableName, $noLock) {
            $joinModel = new $key();
            $joinTableName = $joinModel->getTableName();
            $joinTablePK = $joinModel->getPrimaryKey();

            return sprintf("LEFT JOIN %s as t%d ".($noLock ? "WITH (NOLOCK)" : "")." ON t%d.%s=%s.%s", $joinTableName, $i + 1, $i + 1, $joinTablePK, $tableName, $k);
        });

        $tableAliasReplaceMap = [];
        $allAdditionalFieldsMap = array_merge($primaryFields, $additionalFields ?? []);
        $keysCopy = $keys;

        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) use ($keys, &$tableAliasReplaceMap, &$allAdditionalFieldsMap, &$keysCopy) {// Note that $tableAliasReplaceMap, and $allAdditionalFieldsMap must be passed as a reference
            /** @var BaseObject $joinModel */
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
            . (!empty($additionalFields) ? "," . $this->fillAdditionalFieldsSelect($additionalFields, $model, $keys, $tableAliasReplaceMap) : "");

        $sql = (new BaseDAO($model))
            ->select($select)
            ->withNoLock($noLock)
            ->join($joins);
        $sql->setContainer($this->container);

        /** Add to WHERE clause for tables that are part of the multi tenant system (have CompanyID in a field list)
         * and user is a part of the company.
         * =============================================================================== */
        if (isset($fields["CompanyID"]) && !empty($this->IAM->getCompanyID())) {
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

            $queryParam .= (empty($queryParam)) ? " ( " : " AND ( ";
            if (isset($searchableCol[0])) {
                foreach ($searchableCol as $value) {
                    $chunks = explode(' ', $query);
                    foreach ($chunks as $chunk) {
                        $queryParam .= sprintf("(%s.%s LIKE '%%%s%%') OR ", $tableName, $value, $this->escapeQueryParam($chunk));
                    }
                    $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " OR ";
                }
            }

            if (!empty($keys)) {
                $i = 1;
                foreach ($keys as $key) {
                    $joinModel = new $key();
                    $searchableCol = $joinModel->getSearchableColumns();
                    if (isset($searchableCol[0])) {
                        foreach ($searchableCol as $value) {
                            $chunks = explode(' ', $query);
                            foreach ($chunks as $chunk) {
                                $queryParam .= sprintf(" (%s.%s LIKE '%%%s%%') OR ", "t" . $i, $value, $this->escapeQueryParam($chunk));
                            }
                            $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " OR ";
                        }
                    }
                    ++$i;
                }
            }

            $queryParam = substr($queryParam, 0, strlen($queryParam) - 3) . " ) ";
        }

        /** Add WHERE part of the query from searchFields and where array
         * =============================================================================== */
        if (!empty($searchFields)) {
            $where = array_merge($where, $searchFields);
        }

        /** Add special fields to be a part of WHERE clause.
         * =============================================================================== */
        $queryParam .= $this->appendWhereQuery($model, $where, $additionalFields ?? [], $tableAliasReplaceMap);

        /** Add exclude part of the WHERE clause.
         * =============================================================================== */
        if (!empty($ExcludeIDs)) {
            $queryParam .= sprintf(" AND %s.%s NOT IN (%s)", $tableName, $model->getPrimaryKey(), $ExcludeIDs);
        }

        /** Replace placeholders in the final WHERE string.
         * =============================================================================== */
        $queryParam = $this->fillPlaceholderTables($queryParam, $model, $keys, $tableAliasReplaceMap);


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
            'count' => $sql->count()
        ];
    }

    /**
     * @param BaseObject $model
     * @param array $where
     * @return array
     * @throws Exception
     */
    public function readListWhere(BaseObject $model, array $where): array
    {
        return $this->readListBy($model, [], $where);
    }

    public function findBy(BaseObject $model, string $key, string $value): ?array
    {
        return $this->findWhere($model, [
            $key => $value
        ]);
    }

    public function findByID(BaseObject $model, int $id): ?array
    {
        return $this->findWhere($model, [
            $model->getPrimaryKey() => $id
        ]);
    }

    public function findWhere(BaseObject $model, array $where): ?array
    {
        /** Gather information about model.
         * =============================================================================== */
        $additionalFields = $model->getAdditionalFields();
        $tableName = $model->getTableName();
        $fields = $model->getTableFields();
        $primaryFields = $model->getTableFields();
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
        $allAdditionalFieldsMap = array_merge($primaryFields, $additionalFields ?? []);
        $allAdditionalFields = $additionalFields;
        $keysCopy = $keys;

        $joinsSelects = implode(", ", $this->map($keys, function ($key, $i) use ($keys, &$tableAliasReplaceMap, &$allAdditionalFieldsMap, $model, &$keysCopy, &$allAdditionalFields) {// Note that $tableAliasReplaceMap, and $allAdditionalFieldsMap must be passed as a reference
            /** @var BaseObject $joinModel */
            $joinModel = new $key();

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
            $allAdditionalFields = array_merge($allAdditionalFields ?? [], $joinAdditionalFields ?? []);

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
        $select = $tableName . '.*'
            . (!empty($joinsSelects) ? "," . $joinsSelects : "")
            . (!empty($additionalFields) ? "," . $this->fillAdditionalFieldsSelect($additionalFields, $model, $keys, $tableAliasReplaceMap) : "");

        /** Add to WHERE clause for tables that are part of the multi tenant system (have CompanyID in a field list)
         * and user is a part of the company.
         * =============================================================================== */
        if (isset($fields["CompanyID"]) && !empty($this->IAM->getCompanyID())) {
            $queryParam = sprintf("%s.CompanyID=%d", $tableName, $this->IAM->getCompanyID());
        } else {
            $queryParam = "1=1";
        }

        /** Add passed WHERE part of the query.
         * =============================================================================== */
        $queryParam .= $this->appendWhereQuery($model, $where, $additionalFields ?? [], $tableAliasReplaceMap);

        $sql = "SELECT " . $select . " FROM " . $model->getTableName() . (empty($joins) ? '' : " LEFT JOIN " . $joins) . sprintf(" WHERE %s", $queryParam);

        $result = $this->db->select($sql);

        if (isset($result[0])) {
            $result = $result[0];
        } else {
            return null;
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

    public function updateFromDataWhere(BaseObject $model, array $where, array $data): int
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
        $values = rtrim($values ?? "", ',');

        $whereQuery = "";
        foreach ($where as $k => $v) {
            // TODO Clean param and add advanced params (Make function to reuse with the rest)
            $whereQuery .= (empty($whereQuery) ? '' : " AND ") . sprintf(" %s.%s=%s", $model->getTableName(), $k, is_numeric($v) ? $v : "'$v'");
        }

        $sql = sprintf("UPDATE %s SET %s WHERE %s",
            $model->getTableName(), $values, $whereQuery);

        return $this->db->update($sql, $data);
    }

    public function updateFromData(BaseObject $model, int $id, array $data): int
    {
        return $this->updateFromDataWhere($model, [
            $model->getPrimaryKey() => $id
        ], $data);
    }

    public function deleteWhere(BaseObject $model, array $where): int
    {
        $whereQuery = "";
        foreach ($where as $k => $v) {
            // TODO Clean param and add advanced params (Make function to reuse with the rest)
            $whereQuery .= (empty($whereQuery) ? '' : " AND ") . sprintf(" %s.%s=%s", $model->getTableName(), $k, is_numeric($v) ? $v : "'$v'");
        }

        $sql = sprintf('DELETE FROM %s WHERE %s',
            $model->getTableName(),
            $whereQuery
        );

        return $this->db->delete($sql, []);
    }

    public function deleteBy(BaseObject $model, string $key, string $value): int
    {
        return $this->deleteWhere($model, [$key => $value]);
    }

    public function deleteByID(BaseObject $model, int $id): int
    {
        return $this->deleteWhere($model, [$model->getPrimaryKey() => $id]);
    }

    public function archiveByID(BaseObject $model, int $id): int
    {
        return $this->updateFromData($model, $id, [
            'UpdatedByContactID' => $this->IAM->getContactID(),
            'CreateUpdateDate' => $this->currentDateTime(),
            'ArchivedDate' => $this->currentDateTime()
        ]);
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
                if (str_starts_with($type, 'int')) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_NUMBER_INT);
                } else if (str_starts_with($type, 'decimal')) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                } else if (str_starts_with($type, 'datetime') || str_starts_with($type, 'date')) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_DATE);
                } else if (str_starts_with($type, 'time')) {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_INPUT_STRING);
                } else {
                    $value = $this->filterVar($inputData[$name], FILTER_SANITIZE_INPUT_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
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
                    $value = $this->createRandomHash();
                } else if ($name === 'CompanyID') {
                    $value = $this->IAM->getCompanyID();
                }
            }

            if (($value === null) && (str_contains($type, "DEFAULT"))) {
                $array = explode(" ", $type);
                $value = end($array);
            }

            $data[$name] = $value;

            if ((!str_contains($type, 'NULL')) && ($value === null)) {
                var_dump($model::class);
                var_dump($name);
                var_dump($type);die;
                return []; // Exit function with no result if one of required fields is missing
            }
        }

        return $data;
    }

    private function fillPlaceholderTables(string $queryParam, BaseObject $model, array $keys, array $tableAliasReplaceMap): string
    {
        foreach ($keys as $tableOrder) {
            $m = new $tableOrder();
            $queryParam = str_replace("{{" . $m->getTableName() . "}}", !empty($tableAliasReplaceMap) && isset($tableAliasReplaceMap[$m->getTableName()]) ? $tableAliasReplaceMap[$m->getTableName()] : $m->getTableName(), $queryParam);
        }
        return str_replace("{{" . $model->getTableName() . "}}", $model->getTableName(), $queryParam);
    }

    private function fillAdditionalFieldsSelect(array $additionalFields, BaseObject $model, array $keys, array $tableAliasReplaceMap): string
    {
        return implode(", ", str_replace("\n", "", $this->map($additionalFields, function ($val, $i, $k) use ($keys, $model, $tableAliasReplaceMap) {
            return $k . "=" . $this->fillPlaceholderTables($val, $model, $keys, $tableAliasReplaceMap);
        })));
    }

    protected function filterVar($value, int $filter, ?int $option = 0): mixed
    {
        if ($filter == FILTER_SANITIZE_DATE) {
            return $this->sanitizeDate($value);
        } else if ($filter == FILTER_SANITIZE_NUMBER_FLOAT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_SANITIZE_NUMBER_INT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_VALIDATE_EMAIL) {
            return $value;
        } else if ($filter == FILTER_SANITIZE_INPUT_STRING) {
            return $value;
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

    protected function createRandomHash(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * @throws Exception
     */
    private function appendWhereQuery(BaseObject $model, array $where, array $additionalFields, array $tableAliasReplaceMap): string
    {
        $queryParam = "";
        $fields = $model->getTableFields();
        if (!empty($where)) {
            foreach ($where as $key => $value) {
                if (!empty($value)) {
                    if (is_array($value)) {
                        $key = $value[0];
                        if (!empty($fields[$key])) {
                            $searchField = sprintf("%s.%s", $model->getTableName(), $value[0]);
                        } else if (!empty($additionalFields[$key])) {
                            $searchField = $this->fillPlaceholderTables($additionalFields[$key], $model, $model->getTableKeys(), $tableAliasReplaceMap);
                        } else {
                            throw new Exception("UNSUPPORTED_COMPARE_FIELD");
                        }

                        switch ($value[1]) {
                            case '<':
                            case '>':
                            case '<=':
                            case '>=':
                            case '=':
                            case '<>':
                            case 'LIKE':
                                if (str_contains($fields[$key] ?? $additionalFields[$key], 'datetime')) {
                                    $queryParam .= sprintf(" AND (CAST(%s AS DATE) %s CAST('%s' AS DATE))", $searchField, $value[1], $this->escapeQueryParam($value[2]));
                                } else {
                                    $queryParam .= sprintf(" AND %s %s '%s' ", $searchField, $value[1], $this->escapeQueryParam($value[2]));
                                }
                                break;
                            default:
                                throw new Exception("UNSUPPORTED_COMPARE_OPERATION");
                        }
                    } else {
                        $searchField = sprintf("%s.%s", $model->getTableName(), $key);
                        if (!isset($fields[$key]) && !empty($additionalFields[$key])) {
                            $searchField = $this->fillPlaceholderTables($additionalFields[$key], $model, $model->getTableKeys(), $tableAliasReplaceMap);
                        }
                        if (str_contains($value, ',') && $this->endsWithID($searchField)) {
                            $value = explode(',', $value);
                            $value = implode("','", $value);
                            $queryParam .= sprintf(" AND %s IN (%s) ", $searchField, $this->escapeQueryParam($value));
                        } else {
                            $queryParam .= sprintf(" AND %s = '%s' ", $searchField, $this->escapeQueryParam($value));
                        }
                    }
                }
            }
        }

        return $queryParam;
    }

    public function endsWithID($string): bool {
        // Get the length of the string
        $length = strlen($string);
        // Get the length of the substring "ID"
        $substring = "ID";
        $substring_length = strlen($substring);

        // Check if the end of the string matches "ID"
        if ($substring_length > $length) {
            return false;
        }

        return substr($string, -$substring_length) === $substring;
    }

    public function escapeQueryParam($input)
    {
        // Replace single quotes and double quotes
        $input = str_replace("'", "''", $input);
        $input = str_replace('"', '""', $input);

        // Optionally escape other characters like semicolons if necessary
        return str_replace(";", "\\;", $input);
    }
}
