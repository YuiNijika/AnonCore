<?php

namespace Anon\Core\Database\Mongo;

use Anon\Core\Database\Model;
use Anon\Core\Database\QueryBuilder as BaseQueryBuilder;
use RuntimeException;

class QueryBuilder extends BaseQueryBuilder
{
    protected array $sorts = [];
    protected array $pipelineStages = [];

    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
    }

    protected function mongo(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->connection;

        return $connection;
    }

    public function selectRaw(string $expression): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style selectRaw().');
    }

    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        } else {
            $operator = $this->validateOperator((string) $operator);
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false): self
    {
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => $value,
            'case_sensitive' => $caseSensitive,
            'not' => $not,
            'boolean' => $boolean,
        ];

        return $this;
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
        $this->wheres[] = [
            'type' => 'regex',
            'column' => $column,
            'value' => $pattern,
            'case_sensitive' => $caseSensitive,
            'not' => $not,
            'boolean' => $boolean,
        ];

        return $this;
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

    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if ($values === []) {
            $this->wheres[] = [
                'type' => 'document',
                'value' => $this->alwaysFalseClause(),
                'boolean' => $boolean,
            ];

            return $this;
        }

        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'value' => array_values($values),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if ($values === []) {
            $this->wheres[] = [
                'type' => 'document',
                'value' => $this->alwaysTrueClause(),
                'boolean' => $boolean,
            ];

            return $this;
        }

        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'value' => array_values($values),
            'boolean' => $boolean,
        ];

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

        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'value' => array_values($values),
            'not' => $not,
            'boolean' => $boolean,
        ];

        return $this;
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

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    public function whereDocument(array $filter, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'document',
            'value' => $this->mongo()->normalizeFilterDocument($filter),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereDocument(array $filter): self
    {
        return $this->whereDocument($filter, 'OR');
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'not_null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style join().');
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style leftJoin().');
    }

    public function groupBy(string|array $columns): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style groupBy() on find queries.');
    }

    public function having(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style having().');
    }

    public function havingRaw(string $expression, array $bindings = [], string $boolean = 'AND'): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style havingRaw().');
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->sorts[$column] = strtolower($direction) === 'desc' ? -1 : 1;

        return $this;
    }

    public function orderByRaw(string $expression): self
    {
        throw new RuntimeException('MongoDB driver does not support SQL-style orderByRaw().');
    }

    public function project(array $projection): self
    {
        foreach ($projection as $field => $include) {
            if (is_int($field)) {
                if (!is_string($include) || $include === '' || str_contains($include, ' ')) {
                    throw new RuntimeException('MongoDB project() only supports direct field names.');
                }

                $this->select[] = $include;
                continue;
            }

            if (!is_string($field) || $field === '' || str_starts_with($field, '$') || str_contains($field, ' ')) {
                throw new RuntimeException('MongoDB project() only supports direct field names.');
            }

            if ((int) $include === 1) {
                $this->select[] = $field;
                continue;
            }

            throw new RuntimeException('MongoDB project() currently only supports include projection with value 1.');
        }

        $this->select = array_values(array_unique($this->select));

        return $this;
    }

    public function pipeline(array $stages): self
    {
        foreach ($stages as $stage) {
            $this->addPipelineStage($stage);
        }

        return $this;
    }

    public function addPipelineStage(array $stage): self
    {
        if (count($stage) !== 1) {
            throw new RuntimeException('Each MongoDB pipeline stage must contain exactly one operator.');
        }

        $operator = array_key_first($stage);
        if (!is_string($operator) || !str_starts_with($operator, '$')) {
            throw new RuntimeException('MongoDB pipeline stage key must start with $.');
        }

        $this->pipelineStages[] = $this->mongo()->normalizeFilterDocument($stage);

        return $this;
    }

    public function aggregatePipeline(array $stages = [], bool $includeQueryOptions = true): array
    {
        $query = clone $this;

        if ($stages !== []) {
            $query->pipeline($stages);
        }

        return $query->mongo()->aggregateDocuments($query->table, $query->buildAggregatePipeline($includeQueryOptions));
    }

    public function get(): array
    {
        return $this->mongo()->findDocuments($this->table, $this->buildFilter(), $this->buildFindOptions());
    }

    public function first(): array|Model|null
    {
        $query = clone $this;
        $query->limit(1);
        $result = $query->get();

        return $result[0] ?? null;
    }

    public function count(string $column = '*'): int
    {
        return $this->mongo()->countDocuments($this->table, $this->buildFilter());
    }

    public function aggregate(string $function, string $column = '*'): mixed
    {
        $function = strtoupper(trim($function));
        if ($function === 'COUNT') {
            return $this->count($column);
        }

        if ($column === '*') {
            throw new RuntimeException('MongoDB aggregate functions except COUNT require a concrete field.');
        }

        $operator = match ($function) {
            'SUM' => '$sum',
            'AVG' => '$avg',
            'MIN' => '$min',
            'MAX' => '$max',
            default => throw new RuntimeException("Unsupported aggregate function [{$function}] for MongoDB."),
        };

        $pipeline = [[
            '$group' => [
                '_id' => null,
                'aggregate' => [
                    $operator => '$' . ltrim($column, '$'),
                ],
            ],
        ]];

        $rows = $this->aggregatePipeline($pipeline, false);

        return $rows[0]['aggregate'] ?? null;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function paginate(int $perPage = 15, ?int $current = null): array
    {
        if ($current === null) {
            $current = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        }
        if ($current < 1) {
            $current = 1;
        }

        $total = $this->count();
        $offset = ($current - 1) * $perPage;
        $query = clone $this;
        $data = $query->limit($perPage, $offset)->get();

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $current,
            'last_page' => (int) ceil($total / $perPage),
            'data' => $data,
        ];
    }

    public function insert(array $values): bool|string
    {
        return $this->mongo()->insertOne($this->table, $values);
    }

    public function insertAll(array $values): int
    {
        return $this->mongo()->insertMany($this->table, $values);
    }

    public function update(array $values): int
    {
        return $this->updateOperators(['$set' => $this->mongo()->normalizeWriteDocument($values)]);
    }

    public function delete(): int
    {
        return $this->mongo()->deleteMany($this->table, $this->buildFilter());
    }

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

        $affected = 0;

        foreach ($rows as $row) {
            $filter = [];
            foreach ($uniqueBy as $column) {
                $filter[$column] = $this->mongo()->coerceValueForField($column, $row[$column] ?? null);
            }

            $columns = array_keys($row);
            $currentUpdateColumns = $updateColumns ?? array_values(array_diff($columns, $uniqueBy));
            $updateValues = [];
            foreach ($currentUpdateColumns as $column) {
                $updateValues[$column] = $row[$column] ?? null;
            }

            $payload = [];
            if ($updateValues !== []) {
                $payload['$set'] = $this->mongo()->normalizeWriteDocument($updateValues);
            }

            $insertOnly = array_diff_key($row, array_flip($currentUpdateColumns));
            if ($insertOnly !== []) {
                $payload['$setOnInsert'] = $this->mongo()->normalizeWriteDocument($insertOnly);
            }

            $affected += $this->mongo()->updateMany(
                $this->table,
                $filter,
                $payload,
                ['multi' => false, 'upsert' => true]
            );
        }

        return $affected;
    }

    public function cursor(): \Generator
    {
        foreach ($this->mongo()->cursorDocuments($this->table, $this->buildFilter(), $this->buildFindOptions()) as $document) {
            yield $document;
        }
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $operators = ['$inc' => [$column => $amount]];
        if ($extra !== []) {
            $operators['$set'] = $this->mongo()->normalizeWriteDocument($extra);
        }

        return $this->updateOperators($operators);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    public function unset(string|array $fields): int
    {
        $fields = is_array($fields) ? array_values($fields) : [$fields];
        if ($fields === []) {
            return 0;
        }

        $payload = [];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                throw new RuntimeException('MongoDB unset() fields must be non-empty strings.');
            }

            $payload[$field] = '';
        }

        return $this->updateOperators(['$unset' => $payload]);
    }

    public function push(string $column, mixed $value, bool $unique = false): int
    {
        $operator = $unique ? '$addToSet' : '$push';
        $payload = $this->buildArrayMutationPayload($column, $value);

        return $this->updateOperators([$operator => $payload]);
    }

    public function pull(string $column, mixed $value): int
    {
        return $this->updateOperators([
            '$pull' => [
                $column => $this->mongo()->normalizeBsonValue($value, $column),
            ],
        ]);
    }

    public function renameField(string $from, string $to): int
    {
        if ($from === '' || $to === '') {
            throw new RuntimeException('MongoDB renameField() requires non-empty field names.');
        }

        return $this->updateOperators([
            '$rename' => [$from => $to],
        ]);
    }

    public function updateOperators(array $operators, array $options = []): int
    {
        if ($operators === []) {
            return 0;
        }

        return $this->mongo()->updateMany(
            $this->table,
            $this->buildFilter(),
            $this->normalizeUpdateOperators($operators),
            $options
        );
    }

    public function createIndex(array $keys, array $options = []): string
    {
        return $this->mongo()->createIndex($this->table, $keys, $options);
    }

    public function dropIndex(string|array $index): bool
    {
        return $this->mongo()->dropIndex($this->table, $index);
    }

    public function listIndexes(): array
    {
        return $this->mongo()->listIndexes($this->table);
    }

    protected function buildFindOptions(): array
    {
        $options = [];
        $projection = $this->buildProjection();
        if ($projection !== []) {
            $options['projection'] = $projection;
        }
        if ($this->sorts !== []) {
            $options['sort'] = $this->sorts;
        }
        if ($this->limitValue !== null) {
            $options['limit'] = $this->limitValue;
        }
        if ($this->offsetValue > 0) {
            $options['skip'] = $this->offsetValue;
        }

        return $options;
    }

    protected function buildProjection(): array
    {
        if ($this->select === ['*']) {
            return [];
        }

        $projection = [];
        foreach ($this->select as $column) {
            if (!is_string($column) || $column === '*' || str_contains($column, ' ')) {
                throw new RuntimeException('MongoDB projection only supports direct field names.');
            }
            $projection[$column] = 1;
        }

        return $projection;
    }

    protected function buildFilter(): array
    {
        $filter = [];

        foreach ($this->wheres as $index => $where) {
            $clause = $this->buildClause($where);
            if ($index === 0) {
                $filter = $clause;
                continue;
            }

            $filter = $this->combineFilters($filter, $clause, $where['boolean'] ?? 'AND');
        }

        return $filter;
    }

    protected function buildClause(array $where): array
    {
        return match ($where['type']) {
            'basic' => $this->buildBasicClause($where['column'], $where['operator'], $where['value']),
            'in' => [$where['column'] => ['$in' => $this->normalizeValues($where['column'], $where['value'])]],
            'not_in' => [$where['column'] => ['$nin' => $this->normalizeValues($where['column'], $where['value'])]],
            'between' => $this->buildBetweenClause($where['column'], $where['value'], (bool) ($where['not'] ?? false)),
            'null' => [$where['column'] => null],
            'not_null' => [$where['column'] => ['$ne' => null]],
            'document' => $this->mongo()->normalizeFilterDocument($where['value']),
            'like' => $this->buildRegexClause($where['column'], $this->convertLikePatternToRegex($where['value']), !(bool) ($where['case_sensitive'] ?? false), (bool) ($where['not'] ?? false)),
            'regex' => $this->buildRegexClause($where['column'], $where['value'], !(bool) ($where['case_sensitive'] ?? false), (bool) ($where['not'] ?? false)),
            default => throw new RuntimeException("Unsupported MongoDB where type [{$where['type']}]."),
        };
    }

    protected function buildBasicClause(string $column, string $operator, mixed $value): array
    {
        $value = $this->mongo()->coerceValueForField($column, $value);
        $normalized = strtoupper($operator);

        return match ($normalized) {
            '=' => [$column => $value],
            '!=', '<>' => [$column => ['$ne' => $value]],
            '>' => [$column => ['$gt' => $value]],
            '>=' => [$column => ['$gte' => $value]],
            '<' => [$column => ['$lt' => $value]],
            '<=' => [$column => ['$lte' => $value]],
            'LIKE', 'ILIKE', 'NOT LIKE', 'NOT ILIKE', 'LIKE BINARY' => $this->buildRegexClause(
                $column,
                $this->convertLikePatternToRegex((string) $value),
                in_array($normalized, ['LIKE BINARY'], true),
                in_array($normalized, ['NOT LIKE', 'NOT ILIKE'], true)
            ),
            'REGEXP', 'RLIKE', 'NOT REGEXP', 'NOT RLIKE', '~', '~*', '!~', '!~*' => $this->buildRegexClause(
                $column,
                (string) $value,
                in_array($normalized, ['~*'], true) ? false : !in_array($normalized, ['~*', '!~*'], true),
                in_array($normalized, ['NOT REGEXP', 'NOT RLIKE', '!~', '!~*'], true)
            ),
            default => throw new RuntimeException("Operator [{$operator}] is not supported by the MongoDB QueryBuilder."),
        };
    }

    protected function buildBetweenClause(string $column, array $values, bool $not): array
    {
        [$from, $to] = $values;
        $range = [
            '$gte' => $this->mongo()->coerceValueForField($column, $from),
            '$lte' => $this->mongo()->coerceValueForField($column, $to),
        ];

        return $not ? [$column => ['$not' => $range]] : [$column => $range];
    }

    protected function buildRegexClause(string $column, string $pattern, bool $caseInsensitive, bool $not): array
    {
        $regexClass = \MongoDB\BSON\Regex::class;
        $regex = new $regexClass($pattern, $caseInsensitive ? 'i' : '');

        return $not ? [$column => ['$not' => $regex]] : [$column => $regex];
    }

    protected function convertLikePatternToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace(['%', '_'], ['.*', '.'], $quoted);

        return '^' . $quoted . '$';
    }

    protected function normalizeValues(string $column, array $values): array
    {
        return array_map(
            fn ($value) => $this->mongo()->coerceValueForField($column, $value),
            array_values($values)
        );
    }

    protected function combineFilters(array $current, array $clause, string $boolean): array
    {
        $boolean = strtoupper($boolean);
        $key = $boolean === 'OR' ? '$or' : '$and';

        if (isset($current[$key]) && count($current) === 1) {
            $current[$key][] = $clause;
            return $current;
        }

        return [$key => [$current, $clause]];
    }

    protected function buildAggregatePipeline(bool $includeQueryOptions = true): array
    {
        $pipeline = [];
        $filter = $this->buildFilter();

        if ($filter !== []) {
            $pipeline[] = ['$match' => $filter];
        }

        foreach ($this->pipelineStages as $stage) {
            $pipeline[] = $stage;
        }

        if (!$includeQueryOptions) {
            return $pipeline;
        }

        $projection = $this->buildProjection();
        if ($projection !== []) {
            $pipeline[] = ['$project' => $projection];
        }

        if ($this->sorts !== []) {
            $pipeline[] = ['$sort' => $this->sorts];
        }

        if ($this->offsetValue > 0) {
            $pipeline[] = ['$skip' => $this->offsetValue];
        }

        if ($this->limitValue !== null) {
            $pipeline[] = ['$limit' => $this->limitValue];
        }

        return $pipeline;
    }

    protected function alwaysFalseClause(): array
    {
        return ['$expr' => ['$eq' => [1, 0]]];
    }

    protected function alwaysTrueClause(): array
    {
        return ['$expr' => ['$eq' => [1, 1]]];
    }

    protected function normalizeUpdateOperators(array $operators): array
    {
        $normalized = [];

        foreach ($operators as $operator => $payload) {
            if (!is_string($operator) || !str_starts_with($operator, '$')) {
                throw new RuntimeException('MongoDB updateOperators() keys must start with $.');
            }

            if (!is_array($payload) || $payload === []) {
                throw new RuntimeException("MongoDB update operator [{$operator}] requires a non-empty array payload.");
            }

            $normalized[$operator] = [];
            foreach ($payload as $field => $value) {
                if (!is_string($field) || $field === '') {
                    throw new RuntimeException("MongoDB update operator [{$operator}] requires non-empty string field names.");
                }

                $normalized[$operator][$field] = match ($operator) {
                    '$unset' => '',
                    '$inc' => $value,
                    '$rename' => (string) $value,
                    default => $this->mongo()->normalizeBsonValue($value, $field),
                };
            }
        }

        return $normalized;
    }

    protected function buildArrayMutationPayload(string $column, mixed $value): array
    {
        if (is_array($value) && $this->isListArray($value)) {
            return [
                $column => [
                    '$each' => array_map(
                        fn ($item) => $this->mongo()->normalizeBsonValue($item, $column),
                        $value
                    ),
                ],
            ];
        }

        return [
            $column => $this->mongo()->normalizeBsonValue($value, $column),
        ];
    }

    protected function isListArray(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
