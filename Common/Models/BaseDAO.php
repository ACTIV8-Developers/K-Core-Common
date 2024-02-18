<?php

namespace Common\Models;

use Core\Container\Interfaces\ContainerAwareInterface;
use Core\Core\Model;
use Core\Database\Interfaces\DatabaseInterface;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Class BaseDAO
 *
 * @property DatabaseInterface $db
 * @method select(string $select)
 * @method where(string|array $where)
 * @method order(string $ascDesc)
 * @method orderBy(string $column)
 * @method start(int $offset)
 * @method limit(int $limit)
 */
class BaseDAO extends Model
{
    /**
     * @var BaseObject
     */
    protected BaseObject $model;

    /**
     * @var string
     */
    protected string $table = '';

    /**
     * @var string
     */
    protected string $pk = '';

    /**
     * @var string
     */
    protected string $order = 'asc';

    /**
     * @var ?string
     */
    protected ?string $orderBy = null;

    /**
     * @var ?string
     */
    protected ?string $groupBy = null;

    /**
     * @var array|string
     */
    protected $where = null;

    /**
     * @var ?int
     */
    protected ?int $start = null;

    /**
     * @var ?int
     */
    protected ?int $limit = null;

    /**
     * @var string|array
     */
    protected $select = '*';

    /**
     * @var array|string
     */
    protected $join = null;

    /**
     * @var ?DatabaseInterface
     */
    public ?DatabaseInterface $database = null;

    /**
     * @param BaseObject $o
     */
    public function __construct(BaseObject $o)
    {
        // Model
        $this->model = $o;
        // Table name
        $this->table = $o->getTableName();
        // Primary key
        $this->pk = $o->getPrimaryKey();
    }

    /**
     * @param int $type
     * @return array|null
     */
    public function getOne(int $type = PDO::FETCH_ASSOC): ?array
    {
        $data = $this->getAll($type);
        if (isset($data[0])) {
            return $data[0];
        }
        return null;
    }

    /**
     * @param int $type
     * @return array
     */
    public function getAll(int $type = PDO::FETCH_ASSOC): array
    {
        $select = is_array($this->select) ? implode(',', $this->select) : $this->select;

        $sql = sprintf('SELECT %s FROM %s', $select, $this->table);

        // Join
        if ($this->join !== null) {
            $sql .= ' LEFT JOIN ' . $this->join;
        }

        // Search params
        $values = [];
        if ($this->where !== null) {
            if (is_array($this->where)) {
                $where = '';
                foreach ($this->where as $key => $value) {
                    if (!empty($where)) {
                        $where .= " AND ";
                    }
                    $where .= ($key . "=:" . $key);
                }
                $sql .= ' WHERE ' . $where;
                $values = $this->where;
            } else {
                $sql .= ' WHERE ' . $this->where;
            }
        }

        // Group by
        if ($this->groupBy !== null) {
            $sql .= sprintf(' GROUP BY %s', $this->groupBy);
        }

        // Result order
        if ($this->orderBy !== null) {
            $sql .= sprintf(' ORDER BY %s %s', $this->orderBy, $this->order);
        }

        // Apply query start and limit
        if ($this->start !== null && $this->limit !== null) {
            if ($this->orderBy === null) {
                $sql .= ' ORDER BY (SELECT NULL)';
            }
            $sql .= sprintf(' OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $this->start, $this->limit);
        }

        return $this->db()->select($sql, $values, $type);
    }

    public function sql(): string
    {
        $select = is_array($this->select) ? implode(',', $this->select) : $this->select;

        $sql = sprintf('SELECT %s FROM %s', $select, $this->table);

        // Join
        if ($this->join !== null) {
            $sql .= ' LEFT JOIN ' . $this->join;
        }

        // Search params
        if ($this->where !== null) {
            if (is_array($this->where)) {
                $where = '';
                foreach ($this->where as $key => $value) {
                    if (!empty($where)) {
                        $where .= " AND ";
                    }
                    $where .= ($key . "=:" . $key);
                }
                $sql .= ' WHERE ' . $where;
            } else {
                $sql .= ' WHERE ' . $this->where;
            }
        }

        // Group by
        if ($this->groupBy !== null) {
            $sql .= sprintf(' GROUP BY %s', $this->groupBy);
        }

        // Result order
        if ($this->orderBy !== null) {
            $sql .= sprintf(' ORDER BY %s %s', $this->orderBy, $this->order);
        }

        // Apply query start and limit
        if ($this->start !== null && $this->limit !== null) {
            $sql .= sprintf(' OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $this->start, $this->limit);
        }

        return $sql;
    }

    /**
     * @param int
     * @return array
     */
    public function get($id): ?array
    {
        $data = $this->db()->select(sprintf('SELECT * FROM %s WHERE %s=:id',
                $this->table, $this->pk)
            , ['id' => $id]);

        if (isset($data[0])) {
            return $data[0];
        }

        return null;
    }

    /**
     * @return int
     */
    public function countAll(): int
    {
        return $this->db()->select(sprintf('SELECT COUNT(%s) as cnt FROM %s',
            $this->pk,
            $this->table
        ))[0]['cnt'];
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $sql = sprintf('SELECT COUNT(%s) as cnt FROM %s',
            $this->table . "." . $this->pk,
            $this->table
        );

        // Join
        if ($this->join !== null) {
            $sql .= ' LEFT JOIN ' . $this->join;
        }

        // Search params
        $values = [];
        if ($this->where !== null) {
            if (is_array($this->where)) {
                $where = '';
                foreach ($this->where as $key => $value) {
                    if (!empty($where)) {
                        $where .= " AND ";
                    }
                    $where .= ($key . "=:" . $key);
                }
                $sql .= ' WHERE ' . $where;
                $values = $this->where;
            } else {
                $sql .= ' WHERE ' . $this->where;
            }
        }

        $result = $this->db()->select($sql, $values);

        return $result[0]['cnt'] ?? 0;
    }

    /**
     * @param array
     * @return ?int
     */
    public function insert(array $data): ?int
    {
        $keys = array_keys($data);
        $columns = implode(',', $keys);
        $values = implode(',:', $keys);

        $this->sqlEscape($values);

        $sql = sprintf("INSERT INTO " . $this->table . " (%s) VALUES (:%s)", $columns, $values);

        if ($this->db()->insert($sql, $data)) {
            return $this->db()->lastInsertId();
        }
        return null;
    }

    /**
     * @param $inp
     * @return array
     */
    protected function sqlEscape($inp)
    {
        if (is_array($inp))
            return array_map(__METHOD__, $inp);

        if (!empty($inp) && is_string($inp)) {
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);
        }

        return $inp;
    }

    /**
     * @param int
     * @param array
     * @return int
     */
    public function update($id, array $data): int
    {
        $values = '';
        foreach ($data as $key => $value) {
            $values .= ($key . "=:" . $key . ",");
        }
        $values = rtrim($values, ',');

        $sql = sprintf("UPDATE %s SET %s WHERE %s=%s",
            $this->table, $values, $this->pk, is_string($id) ? "'$id'" : $id);

        return $this->db()->update($sql, $data);
    }

    public function bulkUpdate(array $ids, array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $values = '';
        foreach ($data as $key => $value) {
            if ($value !== "") {
                $values .= ($key . "=" . (is_string($value) ? "'$value'" : $value) . ",");
            } else {
                $values .= ($key . "=" . 'null' . ",");
            }
        }
        $values = rtrim($values, ',');

        $finalIds = implode(",", $ids);

        $sql = sprintf("UPDATE %s SET %s WHERE %s IN (%s)",
            $this->table, $values, $this->pk, $finalIds);

        return $this->db()->update($sql, []);
    }

    public function bulkInsert(array $data): int
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
            $this->sqlEscape($val);
        }
        $val = implode(",", $val);
        $sql = sprintf("INSERT INTO " . $this->table . " (%s) VALUES %s", $columns, $val);

        return $this->db()->insert($sql, $insertData);
    }

    public function updateWhere($where, $data): int
    {
        $values = '';
        foreach ($data as $key => $value) {
            $values .= ($key . "=:" . $key . ",");
        }
        $values = rtrim($values, ',');

        $sql = sprintf("UPDATE %s SET %s",
            $this->table, $values);

        if ($where !== null) {
            if (is_array($where)) {
                $whereTmp = '';
                foreach ($where as $key => $value) {
                    if (!empty($whereTmp)) {
                        $whereTmp .= " AND ";
                    }
                    $whereTmp .= ($key . "=:" . $key);
                }
                $sql .= ' WHERE ' . $whereTmp;
            } else {
                $sql .= ' WHERE ' . $where;
            }
        }

        return $this->db()->update($sql, array_merge(is_array($where) ? $where : [], $data));
    }

    /**
     * @param int
     * @return bool
     */
    public function deleteWhere($where): bool
    {
        $sql = sprintf('DELETE FROM %s',
            $this->table
        );

        if ($where !== null) {
            if (is_array($where)) {
                $whereTmp = '';
                foreach ($where as $key => $value) {
                    if (!empty($whereTmp)) {
                        $whereTmp .= " AND ";
                    }
                    $whereTmp .= ($key . "=:" . $key);
                }
                $sql .= ' WHERE ' . $whereTmp;
            } else {
                $sql .= ' WHERE ' . $where;
            }
        }

        return $this->db()->delete($sql, is_array($where) ? $where : []);
    }

    /**
     * @param int
     * @return bool
     */
    public function delete($id): bool
    {
        $sql = sprintf('DELETE FROM %s WHERE %s=%s',
            $this->table,
            $this->pk,
            $id
        );

        return $this->db()->delete($sql, []);
    }

    /**
     * Generic setter for class variables
     * @param string
     * @param mixed
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        $this->$name = $arguments[0];
        return $this;
    }

    /**
     * @return BaseObject BaseObject
     */
    public function getModel(): BaseObject
    {
        return $this->model;
    }

    public function setContainer(ContainerInterface $container): ContainerAwareInterface
    {
        $this->container = $container;
        $this->database = $container['db'];
        return $this;
    }

    private function db(): DatabaseInterface
    {
        return $this->database;
    }

    public function setDb(DatabaseInterface $db): void
    {
        $this->database = $db;
    }
}