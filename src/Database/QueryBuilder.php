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
    protected array $havings = [];
    protected array $joins = [];
    protected array $bindings = [];
    protected string $orderBy = '';
    protected string $limit = '';
    protected string $groupBy = '';
    protected ?int $limitValue = null;
    protected int $offsetValue = 0;

    /**
     * @var array 允许的操作符
     */
    protected array $allowedOperators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*'
    ];

    protected array $allowedJoinTypes = ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 验证操作符是否合法，防止 SQL 注入
     */
    protected function validateOperator(string $operator): string
    {
        $operator = strtolower(trim($operator));
        if (!in_array($operator, $this->allowedOperators, true)) {
            throw new \InvalidArgumentException("Illegal operator [{$operator}] and could cause SQL injection.");
        }

        return $this->normalizeOperatorForDriver($operator);
    }

    protected function normalizeOperatorForDriver(string $operator): string
    {
        $driver = strtolower((string) $this->connection->getConfig('type', 'mysql'));

        if ($operator === 'ilike' && $driver !== 'pgsql') {
            return 'LIKE';
        }

        if ($operator === 'not ilike' && $driver !== 'pgsql') {
            return 'NOT LIKE';
        }

        return strtoupper($operator);
    }

    /**
     * 设置操作表
     * @param string $table 表名
     * @param bool $prefix 是否自动添加表前缀
     * @return self
     */
    public function table(string $table, bool $prefix = true): self
    {
        $this->assertIdentifier($table, true);

        if ($prefix) {
            $tablePrefix = $this->connection->getConfig('DATABASE_PREFIX', '');
            if ($tablePrefix === '') {
                $tablePrefix = $this->connection->getConfig('prefix', '');
            }
            if ($tablePrefix !== '') {
                $this->assertIdentifier($tablePrefix . $table, true);
            }
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
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column, true);
        }

        $this->select = $columns;
        return $this;
    }

    public function selectRaw(string $expression): self
    {
        $this->assertRawSqlFragment($expression);
        $this->select[] = $expression;

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
        } else {
            $operator = $this->validateOperator((string) $operator);
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
     * 增加 LIKE 条件，并根据驱动自动处理大小写兼容
     */
    public function whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false): self
    {
        $column = $this->wrap($column);
        $operator = $not ? 'NOT LIKE' : 'LIKE';
        $driver = $this->driver();

        if ($driver === 'pgsql') {
            $operator = $caseSensitive
                ? ($not ? 'NOT LIKE' : 'LIKE')
                : ($not ? 'NOT ILIKE' : 'ILIKE');

            return $this->addWhereSql("{$column} {$operator} ?", [$value], $boolean, 'like');
        }

        if ($caseSensitive && $driver === 'mysql') {
            $operator = $not ? 'NOT LIKE BINARY' : 'LIKE BINARY';
            return $this->addWhereSql("{$column} {$operator} ?", [$value], $boolean, 'like');
        }

        if ($caseSensitive) {
            return $this->addWhereSql("{$column} {$operator} ?", [$value], $boolean, 'like');
        }

        return $this->addWhereSql("LOWER({$column}) {$operator} LOWER(?)", [$value], $boolean, 'like');
    }

    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): self
    {
        return $this->whereLike($column, $value, $caseSensitive, 'OR');
    }

    public function whereNotLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND'): self
    {
        return $this->whereLike($column, $value, $caseSensitive, $boolean, true);
    }

    public function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): self
    {
        return $this->whereNotLike($column, $value, $caseSensitive, 'OR');
    }

    public function whereRegex(string $column, string $pattern, bool $caseSensitive = true, string $boolean = 'AND', bool $not = false): self
    {
        [$sql, $bindings] = $this->buildRegexCondition($column, $pattern, $caseSensitive, $not);

        return $this->addWhereSql($sql, $bindings, $boolean, 'regex');
    }

    public function orWhereRegex(string $column, string $pattern, bool $caseSensitive = true): self
    {
        return $this->whereRegex($column, $pattern, $caseSensitive, 'OR');
    }

    public function whereNotRegex(string $column, string $pattern, bool $caseSensitive = true, string $boolean = 'AND'): self
    {
        return $this->whereRegex($column, $pattern, $caseSensitive, $boolean, true);
    }

    public function orWhereNotRegex(string $column, string $pattern, bool $caseSensitive = true): self
    {
        return $this->whereNotRegex($column, $pattern, $caseSensitive, 'OR');
    }

    public function whereJsonValue(string $column, string $path, mixed $value, string $boolean = 'AND', bool $not = false): self
    {
        [$expression, $bindings] = $this->buildJsonValueCondition($column, $path, $value, $not);

        return $this->addWhereSql($expression, $bindings, $boolean, 'json');
    }

    public function orWhereJsonValue(string $column, string $path, mixed $value): self
    {
        return $this->whereJsonValue($column, $path, $value, 'OR');
    }

    public function whereJsonNotValue(string $column, string $path, mixed $value, string $boolean = 'AND'): self
    {
        return $this->whereJsonValue($column, $path, $value, $boolean, true);
    }

    public function orWhereJsonNotValue(string $column, string $path, mixed $value): self
    {
        return $this->whereJsonNotValue($column, $path, $value, 'OR');
    }

    public function whereJsonLike(string $column, string $path, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false): self
    {
        [$expression, $bindings] = $this->buildJsonLikeCondition($column, $path, $value, $caseSensitive, $not);

        return $this->addWhereSql($expression, $bindings, $boolean, 'json_like');
    }

    public function orWhereJsonLike(string $column, string $path, string $value, bool $caseSensitive = false): self
    {
        return $this->whereJsonLike($column, $path, $value, $caseSensitive, 'OR');
    }

    public function whereJsonNotLike(string $column, string $path, string $value, bool $caseSensitive = false, string $boolean = 'AND'): self
    {
        return $this->whereJsonLike($column, $path, $value, $caseSensitive, $boolean, true);
    }

    public function orWhereJsonNotLike(string $column, string $path, string $value, bool $caseSensitive = false): self
    {
        return $this->whereJsonNotLike($column, $path, $value, $caseSensitive, 'OR');
    }

    public function whereJsonIn(string $column, string $path, array $values, string $boolean = 'AND', bool $not = false): self
    {
        [$expression, $bindings] = $this->buildJsonInCondition($column, $path, $values, $not);

        return $this->addWhereSql($expression, $bindings, $boolean, 'json_in');
    }

    public function orWhereJsonIn(string $column, string $path, array $values): self
    {
        return $this->whereJsonIn($column, $path, $values, 'OR');
    }

    public function whereJsonNotIn(string $column, string $path, array $values, string $boolean = 'AND'): self
    {
        return $this->whereJsonIn($column, $path, $values, $boolean, true);
    }

    public function orWhereJsonNotIn(string $column, string $path, array $values): self
    {
        return $this->whereJsonNotIn($column, $path, $values, 'OR');
    }

    public function whereJsonContains(string $column, string $path, mixed $value, string $boolean = 'AND', bool $not = false): self
    {
        [$expression, $bindings] = $this->buildJsonContainsCondition($column, $path, $value, $not);

        return $this->addWhereSql($expression, $bindings, $boolean, 'json_contains');
    }

    public function orWhereJsonContains(string $column, string $path, mixed $value): self
    {
        return $this->whereJsonContains($column, $path, $value, 'OR');
    }

    public function whereJsonNotContains(string $column, string $path, mixed $value, string $boolean = 'AND'): self
    {
        return $this->whereJsonContains($column, $path, $value, $boolean, true);
    }

    public function orWhereJsonNotContains(string $column, string $path, mixed $value): self
    {
        return $this->whereJsonNotContains($column, $path, $value, 'OR');
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
        $this->bindings = array_merge($this->bindings, array_values($values));

        return $this;
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            $this->wheres[] = ['type' => 'raw', 'sql' => '1 = 1', 'boolean' => $boolean];
            return $this;
        }

        $column = $this->wrap($column);
        $placeholders = str_repeat('?, ', count($values) - 1) . '?';
        $this->wheres[] = [
            'type' => 'not_in',
            'sql' => "{$column} NOT IN ({$placeholders})",
            'boolean' => $boolean,
        ];
        $this->bindings = array_merge($this->bindings, array_values($values));

        return $this;
    }

    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->whereNotIn($column, $values, 'OR');
    }

    public function whereBetween(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if (count($values) !== 2) {
            throw new \InvalidArgumentException('WhereBetween expects exactly two boundary values.');
        }

        $column = $this->wrap($column);
        $operator = $not ? 'NOT BETWEEN' : 'BETWEEN';

        return $this->addWhereSql("{$column} {$operator} ? AND ?", array_values($values), $boolean, 'between');
    }

    public function orWhereBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'OR');
    }

    public function whereNotBetween(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    public function orWhereNotBetween(string $column, array $values): self
    {
        return $this->whereNotBetween($column, $values, 'OR');
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
        $table = $this->wrap($table);
        $first = $this->wrap($first);
        $second = $this->wrap($second);
        $operator = $this->validateOperator($operator);
        $type = strtoupper(trim($type));

        if (!in_array($type, $this->allowedJoinTypes, true)) {
            throw new \InvalidArgumentException("Illegal join type [{$type}] and could cause SQL injection.");
        }

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
        if (is_array($columns)) {
            $columns = implode(', ', array_map([$this, 'wrap'], $columns));
        } else {
            $columns = $this->wrap($columns);
        }
        $this->groupBy = "GROUP BY {$columns}";
        return $this;
    }

    public function having(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        } else {
            $operator = $this->validateOperator((string) $operator);
        }

        $column = $this->wrap($column);

        return $this->addHavingSql("{$column} {$operator} ?", [$value], $boolean, 'basic');
    }

    public function orHaving(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->having($column, $operator, $value, 'OR');
    }

    public function havingRaw(string $expression, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->assertRawSqlFragment($expression);

        return $this->addHavingSql($expression, $bindings, $boolean, 'raw');
    }

    /**
     * 设置排序
     * @param string $column 字段名
     * @param string $direction 排序方向 asc/desc
     * @return self
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $column = $this->wrap($column);
        $this->orderBy = "ORDER BY {$column} {$direction}";
        return $this;
    }

    public function orderByRaw(string $expression): self
    {
        $this->assertRawSqlFragment($expression);
        $this->orderBy = "ORDER BY {$expression}";

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
        $this->limitValue = max(0, $limit);
        $this->offsetValue = max(0, $offset);
        $this->limit = '';
        return $this;
    }

    /**
     * 设置偏移量
     * @param int $offset 偏移量
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offsetValue = max(0, $offset);
        $this->limit = '';
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

    public function pluck(string $column, ?string $key = null): array
    {
        $query = clone $this;
        $columns = $key === null ? [$column] : [$column, $key];
        $rows = $query->select($columns)->get();

        if ($key === null) {
            return array_map(
                fn ($row) => $row[$column] ?? null,
                $rows
            );
        }

        $result = [];
        foreach ($rows as $row) {
            $result[$row[$key] ?? null] = $row[$column] ?? null;
        }

        return $result;
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $columnSql = $this->wrap($column);
        $sets = ["{$columnSql} = {$columnSql} + ?"];
        $bindings = [$amount];

        foreach ($extra as $extraColumn => $value) {
            $wrapped = $this->wrap((string) $extraColumn);
            $sets[] = "{$wrapped} = ?";
            $bindings[] = $value;
        }

        $sql = "UPDATE " . $this->wrap($this->table) . " SET " . implode(', ', $sets) . ' ' . $this->buildWhere();

        return $this->connection->statement($sql, array_merge($bindings, $this->bindings));
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * 统计数量
     * @param string $column 字段名
     * @return int
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): mixed
    {
        return $this->aggregate('SUM', $column);
    }

    public function avg(string $column): mixed
    {
        return $this->aggregate('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * 聚合查询
     */
    public function aggregate(string $function, string $column = '*'): mixed
    {
        $function = strtoupper(trim($function));
        $allowed = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

        if (!in_array($function, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported aggregate function [{$function}].");
        }

        $query = clone $this;
        $aggregateColumn = $column === '*' ? '*' : $query->wrap($column);
        $query->select("{$function}({$aggregateColumn}) as aggregate");
        $result = $query->first();

        return $result['aggregate'] ?? null;
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
        $totalQuery->limitValue = null;
        $totalQuery->offsetValue = 0;
        $totalQuery->orderBy = '';
        $total = $totalQuery->requiresGroupedPaginationCount()
            ? count($totalQuery->get())
            : $totalQuery->count();

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

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'asc');
    }

    protected function requiresGroupedPaginationCount(): bool
    {
        return $this->groupBy !== '' || $this->havings !== [];
    }

    /**
     * 包装字段名，防止 SQL 关键字冲突或简单的注入
     */
    protected function wrap(string $value): string
    {
        $value = trim($value);

        if ($value === '*') {
            return $value;
        }

        if ($this->isSafeExpression($value)) {
            return $value;
        }

        $this->assertIdentifier($value, true);

        // 根据不同数据库类型使用不同的包装符
        // MySQL 使用 `, PgSQL / SQLite / Oracle 使用 ", SQLServer 使用 []
        $driver = strtolower($this->connection->getConfig('type'));
        $wrapCharLeft = '`';
        $wrapCharRight = '`';
        
        if (in_array($driver, ['pgsql', 'sqlite', 'oracle', 'oci'], true)) {
            $wrapCharLeft = '"';
            $wrapCharRight = '"';
        } elseif ($driver === 'sqlsrv') {
            $wrapCharLeft = '[';
            $wrapCharRight = ']';
        }

        if (str_contains($value, '.')) {
            return implode('.', array_map(fn($part) => $part === '*' ? $part : "{$wrapCharLeft}{$part}{$wrapCharRight}", explode('.', $value)));
        }

        return "{$wrapCharLeft}{$value}{$wrapCharRight}";
    }

    protected function assertIdentifier(string $value, bool $allowExpression = false): void
    {
        $value = trim($value);

        if ($value === '*' || $value === '') {
            if ($value === '') {
                throw new \InvalidArgumentException('Database identifier cannot be empty.');
            }
            return;
        }

        if ($allowExpression && $this->isSafeExpression($value)) {
            return;
        }

        $parts = explode('.', $value);
        foreach ($parts as $part) {
            if ($part === '*') {
                continue;
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new \InvalidArgumentException("Illegal database identifier [{$value}] and could cause SQL injection.");
            }
        }
    }

    protected function isSafeExpression(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*\s+as\s+[A-Za-z_][A-Za-z0-9_]*$/i', $value) === 1
            || preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\((\*|[A-Za-z_][A-Za-z0-9_\.]*?)\)(\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?$/i', $value) === 1;
    }

    /**
     * 插入数据
     * @param array $values 关联数组键值对
     * @return bool|string 成功返回最后插入的ID，失败抛出异常
     */
    public function insert(array $values): bool|string
    {
        return $this->insertGetId($values);
    }

    public function insertGetId(array $values, string $primaryKey = 'id', ?string $sequence = null): bool|string
    {
        $returnedId = $this->tryInsertReturningId($values, $primaryKey);
        if ($returnedId !== null) {
            return $returnedId;
        }

        [$sql, $bindings] = $this->buildInsertStatement($values);
        $this->connection->statement($sql, $bindings);

        $id = $this->connection->tryLastInsertId($sequence);
        if ($id !== null) {
            return $id;
        }

        if (array_key_exists($primaryKey, $values) && $values[$primaryKey] !== null) {
            return (string) $values[$primaryKey];
        }

        return true;
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
        $firstRowKeys = array_keys($firstRow);
        $columns = implode(', ', array_map([$this, 'wrap'], $firstRowKeys));

        $placeholders = [];
        $bindings = [];

        $singlePlaceholder = '(' . implode(', ', array_fill(0, count($firstRowKeys), '?')) . ')';

        foreach ($values as $row) {
            $placeholders[] = $singlePlaceholder;
            foreach ($firstRowKeys as $key) {
                $bindings[] = $row[$key] ?? null;
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
     * 跨驱动 UPSERT。
     * mysql 使用 ON DUPLICATE KEY UPDATE
     * pgsql/sqlite 使用 ON CONFLICT
     * sqlsrv/oracle/oci 使用事务包裹的 update-or-insert 回退
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $updateColumns = null): int
    {
        $rows = $this->normalizeUpsertRows($values);
        if ($rows === []) {
            return 0;
        }

        $uniqueBy = array_values((array) $uniqueBy);
        if ($uniqueBy === []) {
            throw new \InvalidArgumentException('Upsert unique columns cannot be empty.');
        }

        $columns = array_keys($rows[0]);
        $updateColumns ??= array_values(array_diff($columns, $uniqueBy));
        $driver = $this->driver();

        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            return $this->executeSetBasedUpsert($rows, $columns, $uniqueBy, $updateColumns, $driver);
        }

        return $this->executeTransactionalUpsert($rows, $uniqueBy, $updateColumns);
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

        $havingSql = $this->buildHaving();
        if ($havingSql) {
            $sql .= " {$havingSql}";
        }
        
        $orderBy = $this->orderBy;

        if ($this->requiresSyntheticOrderBy()) {
            $orderBy = $this->buildSyntheticOrderBy();
        }

        if ($orderBy) {
            $sql .= " {$orderBy}";
        }
        
        $limitClause = $this->buildLimitClause();
        if ($limitClause !== '') {
            $sql .= " {$limitClause}";
        }
        
        return $sql;
    }

    protected function buildInsertStatement(array $values): array
    {
        $columns = implode(', ', array_map([$this, 'wrap'], array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql = "INSERT INTO " . $this->wrap($this->table) . " ({$columns}) VALUES ({$placeholders})";

        return [$sql, array_values($values)];
    }

    protected function tryInsertReturningId(array $values, string $primaryKey): ?string
    {
        $driver = $this->driver();
        if (!in_array($driver, ['pgsql', 'sqlsrv', 'oracle', 'oci'], true)) {
            return null;
        }

        [$baseSql, $bindings] = $this->buildInsertStatement($values);
        $wrappedPrimaryKey = $this->wrap($primaryKey);
        $alias = 'anon_insert_id';

        if (in_array($driver, ['oracle', 'oci'], true)) {
            return $this->connection->statementReturningValue(
                $baseSql . " RETURNING {$wrappedPrimaryKey} INTO :anon_returning_value",
                $bindings
            );
        }

        $sql = match ($driver) {
            'pgsql' => $baseSql . " RETURNING {$wrappedPrimaryKey} AS {$alias}",
            'sqlsrv' => "INSERT INTO " . $this->wrap($this->table)
                . " (" . implode(', ', array_map([$this, 'wrap'], array_keys($values))) . ")"
                . " OUTPUT INSERTED.{$wrappedPrimaryKey} AS {$alias}"
                . " VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")",
            default => $baseSql,
        };

        $rows = $this->connection->select($sql, $bindings);
        $value = $rows[0][$alias] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected function buildLimitClause(): string
    {
        if ($this->limitValue === null && $this->offsetValue === 0) {
            return $this->limit;
        }

        $driver = strtolower((string) $this->connection->getConfig('type', 'mysql'));
        $limit = $this->limitValue;
        $offset = $this->offsetValue;

        return match ($driver) {
            'pgsql' => $limit === null
                ? "OFFSET {$offset}"
                : ($offset > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "LIMIT {$limit}"),
            'sqlite' => $limit === null
                ? "LIMIT -1 OFFSET {$offset}"
                : ($offset > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "LIMIT {$limit}"),
            'sqlsrv', 'oracle', 'oci' => $limit === null
                ? "OFFSET {$offset} ROWS"
                : "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY",
            default => $limit === null
                ? "LIMIT {$offset}, 18446744073709551615"
                : "LIMIT {$offset}, {$limit}",
        };
    }

    protected function requiresSyntheticOrderBy(): bool
    {
        if ($this->orderBy !== '') {
            return false;
        }

        $driver = strtolower((string) $this->connection->getConfig('type', 'mysql'));

        if ($driver === 'sqlsrv') {
            return $this->limitValue !== null || $this->offsetValue > 0;
        }

        return in_array($driver, ['oracle', 'oci'], true) && $this->offsetValue > 0;
    }

    protected function buildSyntheticOrderBy(): string
    {
        $driver = strtolower((string) $this->connection->getConfig('type', 'mysql'));

        return $driver === 'sqlsrv' ? 'ORDER BY (SELECT 0)' : 'ORDER BY 1';
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

    protected function buildHaving(): string
    {
        if (empty($this->havings)) {
            return '';
        }

        $sql = '';
        foreach ($this->havings as $index => $having) {
            $boolean = $index > 0 ? " {$having['boolean']} " : 'HAVING ';
            $sql .= $boolean . $having['sql'];
        }

        return $sql;
    }

    protected function addWhereSql(string $sql, array $bindings, string $boolean, string $type = 'raw'): self
    {
        $this->wheres[] = [
            'type' => $type,
            'sql' => $sql,
            'boolean' => $boolean,
        ];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    protected function addHavingSql(string $sql, array $bindings, string $boolean, string $type = 'raw'): self
    {
        $this->havings[] = [
            'type' => $type,
            'sql' => $sql,
            'boolean' => $boolean,
        ];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    protected function normalizeUpsertRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $isAssoc = array_keys($values) !== range(0, count($values) - 1);
        $rows = $isAssoc ? [$values] : $values;
        $firstRow = $rows[0] ?? [];

        if (!is_array($firstRow) || $firstRow === []) {
            throw new \InvalidArgumentException('Upsert values must be a non-empty associative array or array of associative arrays.');
        }

        $columns = array_keys($firstRow);

        return array_map(function ($row) use ($columns) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Each upsert row must be an associative array.');
            }

            $normalized = [];
            foreach ($columns as $column) {
                $normalized[$column] = $row[$column] ?? null;
            }

            return $normalized;
        }, $rows);
    }

    protected function executeSetBasedUpsert(array $rows, array $columns, array $uniqueBy, array $updateColumns, string $driver): int
    {
        $wrappedColumns = implode(', ', array_map([$this, 'wrap'], $columns));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = "INSERT INTO " . $this->wrap($this->table) . " ({$wrappedColumns}) VALUES {$placeholders}";
        $sql .= match ($driver) {
            'mysql' => $this->buildMysqlUpsertClause($updateColumns),
            'pgsql', 'sqlite' => $this->buildConflictUpsertClause($uniqueBy, $updateColumns),
            default => '',
        };

        return $this->connection->statement($sql, $bindings);
    }

    protected function buildMysqlUpsertClause(array $updateColumns): string
    {
        if ($updateColumns === []) {
            return '';
        }

        $assignments = [];
        foreach ($updateColumns as $column) {
            $wrapped = $this->wrap($column);
            $assignments[] = "{$wrapped} = VALUES({$wrapped})";
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }

    protected function buildConflictUpsertClause(array $uniqueBy, array $updateColumns): string
    {
        $conflictColumns = implode(', ', array_map([$this, 'wrap'], $uniqueBy));

        if ($updateColumns === []) {
            return " ON CONFLICT ({$conflictColumns}) DO NOTHING";
        }

        $assignments = [];
        foreach ($updateColumns as $column) {
            $wrapped = $this->wrap($column);
            $assignments[] = "{$wrapped} = EXCLUDED.{$wrapped}";
        }

        return " ON CONFLICT ({$conflictColumns}) DO UPDATE SET " . implode(', ', $assignments);
    }

    protected function executeTransactionalUpsert(array $rows, array $uniqueBy, array $updateColumns): int
    {
        return $this->connection->transaction(function () use ($rows, $uniqueBy, $updateColumns) {
            $affected = 0;

            foreach ($rows as $row) {
                $query = new self($this->connection);
                $query->table($this->table, false);
                $this->applyUniqueConstraints($query, $uniqueBy, $row);

                if ($query->exists()) {
                    if ($updateColumns === []) {
                        continue;
                    }

                    $updates = [];
                    foreach ($updateColumns as $column) {
                        $updates[$column] = $row[$column] ?? null;
                    }

                    $affected += $query->update($updates);
                    continue;
                }

                $query = new self($this->connection);
                $query->table($this->table, false);
                $query->insert($row);
                $affected++;
            }

            return $affected;
        });
    }

    protected function applyUniqueConstraints(self $query, array $uniqueBy, array $row): void
    {
        foreach ($uniqueBy as $column) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                $query->whereNull($column);
                continue;
            }

            $query->where($column, $value);
        }
    }

    protected function buildRegexCondition(string $column, string $pattern, bool $caseSensitive, bool $not): array
    {
        $column = $this->wrap($column);
        $driver = $this->driver();

        return match ($driver) {
            'pgsql' => [
                "{$column} " . ($not
                    ? ($caseSensitive ? '!~' : '!~*')
                    : ($caseSensitive ? '~' : '~*')) . " ?",
                [$pattern],
            ],
            'mysql' => [
                "{$column} " . ($not ? 'NOT REGEXP' : 'REGEXP') . ($caseSensitive ? ' BINARY ?' : ' ?'),
                [$pattern],
            ],
            'oracle', 'oci' => [
                ($not ? 'NOT ' : '') . "REGEXP_LIKE({$column}, ?, ?)",
                [$pattern, $caseSensitive ? 'c' : 'i'],
            ],
            'sqlite' => throw new \RuntimeException('SQLite regex queries require a custom REGEXP function and are not enabled by default.'),
            'sqlsrv' => throw new \RuntimeException('SQL Server does not provide a portable native regex operator in the current QueryBuilder.'),
            default => throw new \RuntimeException("Regex queries are not supported for driver [{$driver}]."),
        };
    }

    protected function buildJsonValueCondition(string $column, string $path, mixed $value, bool $not): array
    {
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException('whereJsonValue() only supports scalar values. Use native SQL for JSON objects or arrays.');
        }

        $expression = $this->buildJsonValueExpression($column, $path);
        if ($value === null) {
            return [$expression . ($not ? ' IS NOT NULL' : ' IS NULL'), []];
        }

        $operator = $not ? '<>' : '=';

        return [$expression . " {$operator} ?", [$this->normalizeJsonScalarBinding($value)]];
    }

    protected function buildJsonLikeCondition(string $column, string $path, string $value, bool $caseSensitive, bool $not): array
    {
        $expression = $this->buildJsonValueExpression($column, $path);
        $driver = $this->driver();
        $operator = $not ? 'NOT LIKE' : 'LIKE';

        if ($driver === 'pgsql') {
            $operator = $caseSensitive
                ? ($not ? 'NOT LIKE' : 'LIKE')
                : ($not ? 'NOT ILIKE' : 'ILIKE');

            return ["{$expression} {$operator} ?", [$value]];
        }

        if ($caseSensitive && $driver === 'mysql') {
            $operator = $not ? 'NOT LIKE BINARY' : 'LIKE BINARY';

            return ["{$expression} {$operator} ?", [$value]];
        }

        if ($caseSensitive) {
            return ["{$expression} {$operator} ?", [$value]];
        }

        return ["LOWER({$expression}) {$operator} LOWER(?)", [$value]];
    }

    protected function buildJsonInCondition(string $column, string $path, array $values, bool $not): array
    {
        if ($values === []) {
            return [$not ? '1 = 1' : '1 = 0', []];
        }

        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException('whereJsonIn() only supports scalar values. Use native SQL for JSON objects or arrays.');
            }
        }

        $expression = $this->buildJsonValueExpression($column, $path);
        $placeholders = str_repeat('?, ', count($values) - 1) . '?';
        $operator = $not ? 'NOT IN' : 'IN';
        $bindings = array_map([$this, 'normalizeJsonScalarBinding'], array_values($values));

        return ["{$expression} {$operator} ({$placeholders})", $bindings];
    }

    protected function buildJsonContainsCondition(string $column, string $path, mixed $value, bool $not): array
    {
        $driver = $this->driver();
        $jsonValue = $this->encodeJsonBinding($value);

        return match ($driver) {
            'mysql' => [
                'JSON_CONTAINS(' . $this->wrap($column) . ', CAST(? AS JSON), ?) ' . ($not ? '= 0' : '= 1'),
                [$jsonValue, $this->buildJsonPathLiteral($this->parseJsonPath($path, true))],
            ],
            'pgsql' => [
                $this->buildJsonDocumentExpression($column, $path) . ' ' . ($not ? 'NOT ' : '') . '@> CAST(? AS jsonb)',
                [$jsonValue],
            ],
            default => throw new \RuntimeException("JSON contains queries are not supported for driver [{$driver}]."),
        };
    }

    protected function buildJsonValueExpression(string $column, string $path): string
    {
        $wrappedColumn = $this->wrap($column);
        $segments = $this->parseJsonPath($path);
        $driver = $this->driver();

        return match ($driver) {
            'mysql' => 'JSON_UNQUOTE(JSON_EXTRACT(' . $wrappedColumn . ", '" . $this->buildJsonPathLiteral($segments) . "'))",
            'pgsql' => $wrappedColumn . " #>> '{" . implode(',', $segments) . "}'",
            'sqlite' => 'json_extract(' . $wrappedColumn . ", '" . $this->buildJsonPathLiteral($segments) . "')",
            'sqlsrv', 'oracle', 'oci' => 'JSON_VALUE(' . $wrappedColumn . ", '" . $this->buildJsonPathLiteral($segments) . "')",
            default => throw new \RuntimeException("JSON value queries are not supported for driver [{$driver}]."),
        };
    }

    protected function buildJsonDocumentExpression(string $column, string $path): string
    {
        $wrappedColumn = $this->wrap($column);
        $segments = $this->parseJsonPath($path, true);
        $driver = $this->driver();

        return match ($driver) {
            'pgsql' => $segments === []
                ? '(' . $wrappedColumn . ')::jsonb'
                : '((' . $wrappedColumn . ")::jsonb #> '{" . implode(',', $segments) . "}')",
            default => throw new \RuntimeException("JSON document queries are not supported for driver [{$driver}]."),
        };
    }

    protected function parseJsonPath(string $path, bool $allowRoot = false): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('JSON path cannot be empty.');
        }

        if (str_starts_with($path, '$.')) {
            $path = substr($path, 2);
        } elseif ($path === '$') {
            if ($allowRoot) {
                return [];
            }

            throw new \InvalidArgumentException('JSON root path is not supported in the current method.');
        }

        $segments = array_values(array_filter(explode('.', $path), static fn ($segment) => $segment !== ''));
        if ($segments === []) {
            throw new \InvalidArgumentException('JSON path cannot be empty.');
        }

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) === 1) {
                continue;
            }

            if (preg_match('/^[0-9]+$/', $segment) === 1) {
                continue;
            }

            throw new \InvalidArgumentException("Illegal JSON path segment [{$segment}].");
        }

        return $segments;
    }

    protected function buildJsonPathLiteral(array $segments): string
    {
        $path = '$';

        foreach ($segments as $segment) {
            $path .= preg_match('/^[0-9]+$/', $segment) === 1
                ? "[{$segment}]"
                : '.' . $segment;
        }

        return $path;
    }

    protected function normalizeJsonScalarBinding(mixed $value): int|float|string
    {
        if (is_bool($value)) {
            return $this->driver() === 'sqlite'
                ? ($value ? 1 : 0)
                : ($value ? 'true' : 'false');
        }

        return $value;
    }

    protected function encodeJsonBinding(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Failed to encode JSON value for query binding.');
        }

        return $json;
    }

    protected function assertRawSqlFragment(string $expression): void
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw new \InvalidArgumentException('Raw SQL fragment cannot be empty.');
        }

        if (preg_match('/(;|--|\/\*|\*\/)/', $expression) === 1) {
            throw new \InvalidArgumentException('Unsafe raw SQL fragment detected.');
        }
    }

    protected function driver(): string
    {
        return strtolower((string) $this->connection->getConfig('type', 'mysql'));
    }
}
