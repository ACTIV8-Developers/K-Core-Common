<?php

namespace Common\Managers;

use App\Services\IAM\IAM;
use App\Services\IAM\Interfaces\IAMInterface;
use Common\Controllers\RootController;
use Common\DAOTrait;
use Common\Managers\Interfaces\ResourceManagerInterface;
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

    public function readList(BaseObject $model, $where = null)
    {
        return $this->handleResourceRead(
            $this->getDaoForObject(get_class($model)),
            false,
            null,
            $where
        );
    }

    public function readListBy(BaseObject $model, string $key, string $value)
    {
        return $this->getDaoForObject(get_class($model))
            ->where(sprintf("%s.%s='%s'", $model->getTableName(), $key, $value))
            ->getAll();
    }

    public function findBy(BaseObject $model, string $key, string $value)
    {
        return $this->findWhere($model, sprintf("%s.%s='%s'", $model->getTableName(), $key, $value));
    }

    public function findByID(BaseObject $model, int $id)
    {
        return $this->findWhere($model, sprintf("%s.%s='%d'", $model->getTableName(), $model->getPrimaryKey(), $id));
    }

    public function findWhere(BaseObject $model, string $where)
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
        $sql = "SELECT " . $select . " FROM " . $model->getTableName() . " LEFT JOIN " . $joins . sprintf(" WHERE %s", $where);

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

    public function updateFromData(BaseObject $model, int $id, array $data): int
    {
        $fields = $model->getTableFields();

        if (isset($fields['UpdatedByContactID'])) {
            $data['UpdatedByContactID'] = $this->IAM->getContactID();
        }
        if (isset($fields['CreateUpdateDate'])) {
            $data['CreateUpdateDate'] = $this->currentDateTime();
        }

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
        $sql = sprintf('DELETE FROM %s',
            $model->getTableName()
        );

        $where = [
            $key => $value
        ];

        return $this->db->delete($sql, is_array($where) ? $where : []);
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
                    $value = "";
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