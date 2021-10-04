<?php

namespace Common\Models;

use Common\FunctionalTrait;

const SEARCHABLE_COLUMNS = 1;
const DESC_COLUMN = 2;
const EXCEL_COLUMN = 3;
const WHERE = 4;
const VALIDATE = 5;
const TABLES = 6;
const ADDITIONAL_FIELDS = 7;
const DEFAULT_READ = 8;
const GLOBAL_FIELDS = 9;

/**
 * Class BaseObject
 */
abstract class BaseObject
{
    use FunctionalTrait;

    /**
     * @var array
     */
    protected array $fields = [];

    /**
     * @var string
     */
    protected string $table = '';

    /**
     * @var array
     */
    protected array $indices = [];

    /**
     * @var array
     */
    protected array $keys = [];

    /**
     * @var array
     */
    protected array $meta = [];

    /**
     * @var string
     */
    protected string $pk = '';

    /**
     * @param mixed
     * @return BaseObject
     */
    public static function getNew($param = null): BaseObject
    {
        $class = get_called_class();
        return new $class($param);
    }

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return $this->pk;
    }

    /**
     * @return array
     */
    public function getTableFields(): array
    {
        return $this->fields;
    }

    /**
     * @return array
     */
    public function getColumnsForDisplay(): array
    {
        return array_keys($this->fields);
    }

    /**
     * @return array
     */
    public function getTableIndices(): array
    {
        return $this->indices;
    }

    /**
     * @return array
     */
    public function getTableKeys(): array
    {
        return $this->keys;
    }

    public function getSearchableColumns()
    {
        return $this->getMetaByKey(SEARCHABLE_COLUMNS);
    }

    public function getDefaultRead()
    {
        return $this->getMetaByKey(DEFAULT_READ);
    }

    /**
     * @return string
     */
    public function getGlobalFields()
    {
        return $this->getMetaByKey(GLOBAL_FIELDS);
    }

    /**
     * @return array
     */
    public function getTables(): ?array
    {
        return $this->getMetaByKey(TABLES);
    }

    public function getWhere()
    {
        return $this->getMetaByKey(WHERE);
    }

    public function getAdditionalFields()
    {
        return $this->getMetaByKey(ADDITIONAL_FIELDS);
    }

    public function getValidate()
    {
        return $this->getMetaByKey(VALIDATE);
    }

    public function getMetaByKey($key)
    {
        return $this->meta[$key] ?? null;
    }

    public function getDescColumn($prefix = null)
    {
        $pref = ($prefix ?? $this->getTableName()) . ".";

        if (isset($this->meta[DESC_COLUMN]) && $this->meta[DESC_COLUMN]) {
            if (count($this->meta[DESC_COLUMN]) === 1) {
                return $pref . $this->meta[DESC_COLUMN][0];
            } else {
                return $this->map($this->meta[DESC_COLUMN], function ($it) use ($pref) {
                    return $pref . $it;
                });
            }
        } else {
            return $pref . str_replace("ID", "", $this->getPrimaryKey());
        }
    }
}