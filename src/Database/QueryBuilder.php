<?php

namespace Anon\Core\Database;

class QueryBuilder
{
    /**
     * @var Connection 数据库连接实例
     */
    protected Connection $connection;

    /**
     * @var string 操作的表名
     */
    protected string $table = '';
    protected array $select = ['*'];

    /**
     * @var array 查询条件
     */
    protected array $wheres = [];
    protected array $joins = [];
    protected array $bindings = [];
    protected string $orderBy = '';
    protected string $limit = '';
    protected string $groupBy = '';

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 设置操作表
     * @param string $table 表名
     * @param bool $prefix 是否自动添加表前缀
     * @return self
     */
    public function table(string $table, bool $prefix = true): self
    {
        if ($prefix) {
            $tablePrefix = $this->connection->getConfig('prefix', '');
            $this->table = $tablePrefix . $table;
        } else {
            $this->table = $table;
        }
        return $this;
    }

    /**
     * 设置查询字段
     * @param array|string $columns 字段
     * @return self
     */
    public function select(array|string $columns): self
    {
        $this->select = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    /**
     * 增加 WHERE 条件
     * @param string $column 字段名
     * @param mixed $operator 操作符或值
     * @param mixed $value 值
     * @param string $boolean 逻辑连接符 AND/OR
     * @return self
     */
    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $column = $this->wrap($column);
        $this->wheres[] = [
            'type' => 'basic',
            'sql' => "{$column} {$operator} ?",
            'boolean' => $boolean
        ];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * 增加 OR WHERE 条件
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * 增加 WHERE IN 条件
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            // 如果数组为空，构造一个绝对为假的条件
            $this->wheres[] = ['type' => 'raw', 'sql' => '1 = 0', 'boolean' => $boolean];
            return $this;
        }

        $column = $this->wrap($column);
        $placeholders = str_repeat('?, ', count($values) - 1) . '?';
        $this->wheres[] = [
            'type' => 'in',
            'sql' => "{$column} IN ({$placeholders})",
            'boolean' => $boolean
        ];
        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * 增加 WHERE NULL 条件
     */
    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $column = $this->wrap($column);
        $this->wheres[] = [
            'type' => 'null',
            'sql' => "{$column} IS NULL",
            'boolean' => $boolean
        ];
        return $this;
    }

    /**
     * 增加 WHERE NOT NULL 条件
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $column = $this->wrap($column);
        $this->wheres[] = [
            'type' => 'not_null',
            'sql' => "{$column} IS NOT NULL",
            'boolean' => $boolean
        ];
        return $this;
    }

    /**
     * 增加 OR WHERE NOT NULL 条件
     */
    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    /**
     * 增加 OR WHERE NULL 条件
     */
    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    /**
     * JOIN 连表查询
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * LEFT JOIN 连表查询
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * 设置分组
     */
    public function groupBy(string|array $columns): self
    {
        $columns = is_array($columns) ? implode(', ', $columns) : $columns;
        $this->groupBy = "GROUP BY {$columns}";
        return $this;
    }

    /**
     * 设置排序
     * @param string $column 字段名
     * @param string $direction 排序方向 asc/desc
     * @return self
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy = "ORDER BY {$column} " . strtoupper($direction);
        return $this;
    }

    /**
     * 设置限制条数
     * @param int $limit 限制数
     * @param int $offset 偏移量
     * @return self
     */
    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = "LIMIT {$offset}, {$limit}";
        return $this;
    }

    /**
     * 执行查询并获取所有结果
     * @return array
     */
    public function get(): array
    {
        $sql = $this->toSql();
        return $this->connection->select($sql, $this->bindings);
    }

    /**
     * 分块处理数据，极大降低大数据处理时的内存消耗
     * @param int $count 每次获取的数量
     * @param callable $callback 回调函数，返回 false 时终止处理
     * @return bool
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;
        do {
            $clone = clone $this;
            $results = $clone->limit($count, ($page - 1) * $count)->get();
            $countResults = count($results);

            if ($countResults == 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);
            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * 使用游标获取数据
     * @return \Generator
     */
    public function cursor(): \Generator
    {
        $sql = $this->toSql();
        $pdo = $this->connection->getPdo();
        
        $stmt = $pdo->prepare($sql);
        
        foreach ($this->bindings as $key => $value) {
            $stmt->bindValue(is_string($key) ? $key : $key + 1, $value);
        }
        
        $stmt->execute();
        
        while ($record = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield $record;
        }
    }

    /**
     * 执行查询并获取第一条结果
     * @return array|null|Model
     */
    public function first(): array|Model|null
    {
        $query = clone $this;
        $query->limit(1);
        $result = $query->get();
        return !empty($result) ? $result[0] : null;
    }

    /**
     * 获取单条记录的值
     * @param string $column 字段名
     * @return mixed
     */
    public function value(string $column): mixed
    {
        $query = clone $this;
        $query->select($column);
        $result = $query->first();
        return $result[$column] ?? null;
    }

    /**
     * 统计数量
     * @param string $column 字段名
     * @return int
     */
    public function count(string $column = '*'): int
    {
        $query = clone $this;
        $query->select("COUNT({$column}) as aggregate");
        $result = $query->first();
        return (int)($result['aggregate'] ?? 0);
    }

    /**
     * 判断是否存在
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * 分页查询
     * @param int $perPage 每页数量
     * @param int|null $current 当前页码
     * @return array
     */
    public function paginate(int $perPage = 15, ?int $current = null): array
    {
        if ($current === null) {
            $current = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        }
        if ($current < 1) {
            $current = 1;
        }

        $totalQuery = clone $this;
        $totalQuery->select = ['*'];
        $totalQuery->limit = '';
        $totalQuery->orderBy = '';
        $total = $totalQuery->count();

        $lastPage = (int) ceil($total / $perPage);
        $offset = ($current - 1) * $perPage;

        $this->limit($perPage, $offset);
        $data = $this->get();

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $current,
            'last_page' => $lastPage,
            'data' => $data,
        ];
    }

    /**
     * 包装字段名，防止 SQL 关键字冲突或简单的注入
     */
    protected function wrap(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        // 根据不同数据库类型使用不同的包装符
        // MySQL 使用 `, PgSQL 和 Oracle 使用 ", SQLServer 使用 [], SQLite 使用 " 或 `
        $driver = strtolower($this->connection->getConfig('type'));
        $wrapCharLeft = '`';
        $wrapCharRight = '`';
        
        if (in_array($driver, ['pgsql', 'oracle', 'oci'])) {
            $wrapCharLeft = '"';
            $wrapCharRight = '"';
        } elseif ($driver === 'sqlsrv') {
            $wrapCharLeft = '[';
            $wrapCharRight = ']';
        }

        if (str_contains($value, '.')) {
            return implode('.', array_map(fn($part) => $part === '*' ? $part : "{$wrapCharLeft}{$part}{$wrapCharRight}", explode('.', $value)));
        }
        
        // 防止已经被包裹，或者包含聚合函数如 COUNT 等复杂表达式
        if (str_contains($value, $wrapCharLeft) || str_contains($value, '(') || str_contains($value, ' ')) {
            return $value;
        }
        return "{$wrapCharLeft}{$value}{$wrapCharRight}";
    }

    /**
     * 插入数据
     * @param array $values 关联数组键值对
     * @return bool|string 成功返回最后插入的ID，失败抛出异常
     */
    public function insert(array $values): bool|string
    {
        $columns = implode(', ', array_map([$this, 'wrap'], array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        
        $sql = "INSERT INTO " . $this->wrap($this->table) . " ({$columns}) VALUES ({$placeholders})";
        
        $this->connection->statement($sql, array_values($values));
        return $this->connection->lastInsertId();
    }

    /**
     * 批量插入数据，单次 SQL 操作
     * @param array $values 必须是二维关联数组
     * @return int 受影响的行数
     */
    public function insertAll(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        // 取第一行数据的键作为字段名
        $firstRow = reset($values);
        $columns = implode(', ', array_map([$this, 'wrap'], array_keys($firstRow)));

        $placeholders = [];
        $bindings = [];

        $singlePlaceholder = '(' . implode(', ', array_fill(0, count($firstRow), '?')) . ')';

        foreach ($values as $row) {
            $placeholders[] = $singlePlaceholder;
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        $sql = "INSERT INTO " . $this->wrap($this->table) . " ({$columns}) VALUES " . implode(', ', $placeholders);

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * 更新数据
     * @param array $values 更新的键值对
     * @return int 受影响的行数
     */
    public function update(array $values): int
    {
        $sets = [];
        $updateBindings = [];

        foreach ($values as $column => $value) {
            $column = $this->wrap($column);
            $sets[] = "{$column} = ?";
            $updateBindings[] = $value;
        }

        $setSql = implode(', ', $sets);
        $whereSql = $this->buildWhere();
        
        $sql = "UPDATE " . $this->wrap($this->table) . " SET {$setSql} {$whereSql}";
        
        // 合并更新的值绑定和 WHERE 条件的绑定
        $bindings = array_merge($updateBindings, $this->bindings);

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * 删除数据
     * @return int 受影响的行数
     */
    public function delete(): int
    {
        $whereSql = $this->buildWhere();
        $sql = "DELETE FROM " . $this->wrap($this->table) . " {$whereSql}";
        
        return $this->connection->statement($sql, $this->bindings);
    }

    /**
     * 组装 SELECT SQL 语句
     * @return string
     */
    protected function toSql(): string
    {
        // SELECT 的字段不强行全 wrap，因为可能是 RAW 表达式（如 COUNT(id) as count）
        // 建议用户在使用特殊字段时自己确保安全，或我们在 wrap 里做智能判断。
        $selects = implode(', ', $this->select);
        $whereSql = $this->buildWhere();
        $joinSql = implode(' ', $this->joins);
        
        $sql = "SELECT {$selects} FROM " . $this->wrap($this->table);
        
        if ($joinSql) {
            $sql .= " {$joinSql}";
        }
        
        if ($whereSql) {
            $sql .= " {$whereSql}";
        }
        
        if ($this->groupBy) {
            $sql .= " {$this->groupBy}";
        }
        
        if ($this->orderBy) {
            $sql .= " {$this->orderBy}";
        }
        
        if ($this->limit) {
            $sql .= " {$this->limit}";
        }
        
        return $sql;
    }

    /**
     * 组装 WHERE 语句
     * @return string
     */
    protected function buildWhere(): string
    {
        if (empty($this->wheres)) {
            return '';
        }
        
        $sql = '';
        foreach ($this->wheres as $index => $where) {
            $boolean = $index > 0 ? " {$where['boolean']} " : 'WHERE ';
            $sql .= $boolean . $where['sql'];
        }
        
        return $sql;
    }
}