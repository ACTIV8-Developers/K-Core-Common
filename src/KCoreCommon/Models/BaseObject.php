<?php

namespace KCoreCommon\Models;

use App\Controllers\Common\FunctionalTrait;

const SEARCHABLE_COLUMNS = 1;
const DESC_COLUMN = 2;
const EXCEL_COLUMN = 3;
const WHERE = 4;

/**
 * Class BaseObject
 * @package App\Models
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
     * @return object BaseObject
     */
    public static function getNew($param = null)
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