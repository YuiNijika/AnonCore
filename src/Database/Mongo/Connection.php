<?php

namespace Anon\Core\Database\Mongo;

use Anon\Core\Database\Connection as BaseConnection;
use Anon\Core\Database\QueryBuilder as BaseQueryBuilder;
use DateTimeInterface;
use PDO;
use RuntimeException;

class Connection extends BaseConnection
{
    protected mixed $manager = null;
    protected mixed $session = null;

    public function getPdo(): PDO
    {
        throw new RuntimeException('PDO is not available for MongoDB connections.');
    }

    public function getManager(): object
    {
        if ($this->manager === null) {
            $this->connect();
        }

        return $this->manager;
    }

    protected function connect(): void
    {
        if (!class_exists(\MongoDB\Driver\Manager::class)) {
            throw new RuntimeException('MongoDB driver support requires the ext-mongodb extension.');
        }

        $managerClass = \MongoDB\Driver\Manager::class;
        $uri = $this->buildMongoUri();
        $options = $this->config['options'] ?? [];
        $driverOptions = $this->config['driver_options'] ?? [];

        $this->manager = new $managerClass(
            $uri,
            is_array($options) ? $options : [],
            is_array($driverOptions) ? $driverOptions : []
        );
    }

    protected function buildMongoUri(): string
    {
        $uri = trim((string) ($this->config['uri'] ?? ''));
        if ($uri !== '') {
            return $uri;
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (string) ($this->config['port'] ?? 27017);
        $database = (string) ($this->config['database'] ?? '');
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $authSource = (string) ($this->config['auth_source'] ?? $database);

        $credentials = '';
        if ($username !== '') {
            $credentials = rawurlencode($username);
            if ($password !== '') {
                $credentials .= ':' . rawurlencode($password);
            }
            $credentials .= '@';
        }

        $query = [];
        if ($authSource !== '') {
            $query['authSource'] = $authSource;
        }

        $queryString = $query === [] ? '' : ('?' . http_build_query($query));

        return "mongodb://{$credentials}{$host}:{$port}/{$database}{$queryString}";
    }

    public function getDatabaseName(): string
    {
        $database = trim((string) ($this->config['database'] ?? ''));
        if ($database === '') {
            throw new RuntimeException('MongoDB database name cannot be empty.');
        }

        return $database;
    }

    public function getNamespace(string $collection): string
    {
        return $this->getDatabaseName() . '.' . $collection;
    }

    public function getExecutionOptions(): array
    {
        return $this->session === null ? [] : ['session' => $this->session];
    }

    public function table(string $table): BaseQueryBuilder
    {
        return (new QueryBuilder($this))->table($table);
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    public function select(string $sql, array $bindings = []): array
    {
        throw new RuntimeException('Raw SQL select is not supported by MongoDB connections.');
    }

    public function statement(string $sql, array $bindings = []): int
    {
        throw new RuntimeException('Raw SQL statement is not supported by MongoDB connections.');
    }

    public function lastInsertId(?string $sequence = null): string
    {
        throw new RuntimeException('lastInsertId() is not available for MongoDB connections.');
    }

    public function beginTransaction(): void
    {
        $this->getManager();

        if (!method_exists($this->manager, 'startSession')) {
            throw new RuntimeException('MongoDB transactions require session support.');
        }

        if ($this->transactions === 0) {
            $this->session = $this->manager->startSession();
            $this->session->startTransaction();
        }

        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 1 && $this->session !== null) {
            $this->session->commitTransaction();
            $this->endSession();
        }

        $this->transactions = max(0, $this->transactions - 1);
    }

    public function rollBack(): void
    {
        if ($this->transactions === 1 && $this->session !== null) {
            $this->session->abortTransaction();
            $this->endSession();
        }

        $this->transactions = max(0, $this->transactions - 1);
    }

    protected function endSession(): void
    {
        if ($this->session !== null && method_exists($this->session, 'endSession')) {
            $this->session->endSession();
        }

        $this->session = null;
    }

    public function findDocuments(string $collection, array $filter = [], array $options = []): array
    {
        $queryClass = \MongoDB\Driver\Query::class;
        $query = new $queryClass($filter, $options);
        $cursor = $this->getManager()->executeQuery(
            $this->getNamespace($collection),
            $query,
            $this->getExecutionOptions()
        );

        return $this->cursorToArray($cursor);
    }

    public function cursorDocuments(string $collection, array $filter = [], array $options = []): \Generator
    {
        $queryClass = \MongoDB\Driver\Query::class;
        $query = new $queryClass($filter, $options);
        $cursor = $this->getManager()->executeQuery(
            $this->getNamespace($collection),
            $query,
            $this->getExecutionOptions()
        );

        $this->applyCursorTypeMap($cursor);

        foreach ($cursor as $document) {
            yield $this->normalizeDocument($document);
        }
    }

    public function aggregateDocuments(string $collection, array $pipeline): array
    {
        return $this->executeDatabaseCommand([
            'aggregate' => $collection,
            'pipeline' => $pipeline,
            'cursor' => new \stdClass(),
        ]);
    }

    public function countDocuments(string $collection, array $filter = []): int
    {
        $pipeline = [];
        if ($filter !== []) {
            $pipeline[] = ['$match' => $filter];
        }
        $pipeline[] = ['$count' => 'count'];

        $rows = $this->aggregateDocuments($collection, $pipeline);

        return (int) ($rows[0]['count'] ?? 0);
    }

    public function insertOne(string $collection, array $document): string
    {
        $bulk = $this->newBulkWrite();
        $id = $bulk->insert($this->normalizeWriteDocument($document));
        $this->executeBulkWrite($collection, $bulk);

        return $this->normalizeIdentifier($id);
    }

    public function insertMany(string $collection, array $documents): int
    {
        if ($documents === []) {
            return 0;
        }

        $bulk = $this->newBulkWrite();
        foreach ($documents as $document) {
            $bulk->insert($this->normalizeWriteDocument($document));
        }

        $result = $this->executeBulkWrite($collection, $bulk);

        return (int) $result->getInsertedCount();
    }

    public function updateMany(string $collection, array $filter, array $update, array $options = []): int
    {
        $bulk = $this->newBulkWrite();
        $bulk->update($filter, $update, array_merge(['multi' => true], $options));
        $result = $this->executeBulkWrite($collection, $bulk);

        return (int) ($result->getModifiedCount() + $result->getUpsertedCount());
    }

    public function deleteMany(string $collection, array $filter, array $options = []): int
    {
        $bulk = $this->newBulkWrite();
        $bulk->delete($filter, array_merge(['limit' => 0], $options));
        $result = $this->executeBulkWrite($collection, $bulk);

        return (int) $result->getDeletedCount();
    }

    public function listCollections(?array $filter = null): array
    {
        $command = ['listCollections' => 1];
        if ($filter !== null) {
            $command['filter'] = $this->normalizeFilterDocument($filter);
        }

        return $this->executeDatabaseCommand($command);
    }

    public function hasCollection(string $collection): bool
    {
        return $this->listCollections(['name' => $collection]) !== [];
    }

    public function createCollection(string $collection, array $options = []): bool
    {
        if ($this->hasCollection($collection)) {
            return true;
        }

        $command = array_merge(['create' => $collection], $options);
        $this->executeDatabaseCommand($command);

        return true;
    }

    public function dropCollection(string $collection): bool
    {
        if (!$this->hasCollection($collection)) {
            return false;
        }

        $this->executeDatabaseCommand(['drop' => $collection]);

        return true;
    }

    public function modifyCollection(string $collection, array $options): bool
    {
        if ($options === []) {
            return true;
        }

        $this->executeDatabaseCommand(array_merge(['collMod' => $collection], $options));

        return true;
    }

    public function listIndexes(string $collection): array
    {
        return $this->executeDatabaseCommand(['listIndexes' => $collection]);
    }

    public function createIndex(string $collection, array $keys, array $options = []): string
    {
        $normalizedKeys = $this->normalizeIndexKeys($keys);
        $name = (string) ($options['name'] ?? $this->generateIndexName($normalizedKeys));
        $definition = array_merge($options, [
            'key' => $normalizedKeys,
            'name' => $name,
        ]);

        $this->executeDatabaseCommand([
            'createIndexes' => $collection,
            'indexes' => [$definition],
        ]);

        return $name;
    }

    public function dropIndex(string $collection, string|array $index): bool
    {
        $target = is_array($index) ? $this->normalizeIndexKeys($index) : $index;
        $this->executeDatabaseCommand([
            'dropIndexes' => $collection,
            'index' => $target,
        ]);

        return true;
    }

    public function command(array $command): array
    {
        return $this->executeDatabaseCommand($command);
    }

    protected function executeBulkWrite(string $collection, object $bulk): object
    {
        return $this->getManager()->executeBulkWrite(
            $this->getNamespace($collection),
            $bulk,
            $this->getExecutionOptions()
        );
    }

    protected function newBulkWrite(): object
    {
        $bulkWriteClass = \MongoDB\Driver\BulkWrite::class;

        return new $bulkWriteClass();
    }

    protected function executeDatabaseCommand(array $command): array
    {
        $commandClass = \MongoDB\Driver\Command::class;
        $cursor = $this->getManager()->executeCommand(
            $this->getDatabaseName(),
            new $commandClass($this->normalizeFilterDocument($command)),
            $this->getExecutionOptions()
        );

        return $this->cursorToArray($cursor);
    }

    protected function cursorToArray(object $cursor): array
    {
        $this->applyCursorTypeMap($cursor);

        $rows = [];
        foreach ($cursor as $document) {
            $rows[] = $this->normalizeDocument($document);
        }

        return $rows;
    }

    protected function applyCursorTypeMap(object $cursor): void
    {
        if (method_exists($cursor, 'setTypeMap')) {
            $cursor->setTypeMap([
                'root' => 'array',
                'document' => 'array',
                'array' => 'array',
            ]);
        }
    }

    public function normalizeWriteDocument(array $document): array
    {
        $normalized = [];
        foreach ($document as $field => $value) {
            $normalized[$field] = $this->normalizeBsonValue($value, (string) $field);
        }

        return $normalized;
    }

    public function normalizeFilterDocument(array $document, ?string $field = null): array
    {
        if ($this->isListArray($document)) {
            return array_map(
                fn ($value) => $this->normalizeBsonValue($value, $field),
                $document
            );
        }

        $normalized = [];
        foreach ($document as $key => $value) {
            if (is_string($key) && str_starts_with($key, '$')) {
                $normalized[$key] = is_array($value)
                    ? $this->normalizeFilterDocument($value, $field)
                    : $this->normalizeBsonValue($value, $field);
                continue;
            }

            $currentField = is_string($key) ? $key : $field;
            $normalized[$key] = is_array($value)
                ? $this->normalizeFilterDocument($value, $currentField)
                : $this->normalizeBsonValue($value, $currentField);
        }

        return $normalized;
    }

    public function coerceValueForField(string $field, mixed $value): mixed
    {
        return $this->normalizeBsonValue($value, $field);
    }

    public function normalizeBsonValue(mixed $value, ?string $field = null): mixed
    {
        if (is_array($value)) {
            return $this->isListArray($value)
                ? array_map(fn ($item) => $this->normalizeBsonValue($item, $field), $value)
                : $this->normalizeFilterDocument($value, $field);
        }

        if ($value instanceof DateTimeInterface) {
            $utcDateTimeClass = \MongoDB\BSON\UTCDateTime::class;
            $milliseconds = ((int) $value->format('U')) * 1000 + (int) floor(((int) $value->format('u')) / 1000);

            return new $utcDateTimeClass($milliseconds);
        }

        if (is_object($value)) {
            return $value;
        }

        if ($field !== null && $this->looksLikeObjectIdField($field) && is_string($value) && preg_match('/^[a-f0-9]{24}$/i', $value) === 1) {
            $objectIdClass = \MongoDB\BSON\ObjectId::class;
            return new $objectIdClass($value);
        }

        return $value;
    }

    protected function looksLikeObjectIdField(string $field): bool
    {
        $field = strtolower(trim($field));

        return $field === '_id' || str_ends_with($field, '_id');
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

    protected function normalizeIndexKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $field => $direction) {
            if (!is_string($field) || $field === '') {
                throw new RuntimeException('MongoDB index keys must use non-empty string field names.');
            }

            $normalized[$field] = match (true) {
                is_int($direction) => $direction >= 0 ? 1 : -1,
                is_float($direction) => $direction >= 0 ? 1 : -1,
                is_string($direction) => $this->normalizeIndexDirection($direction),
                default => throw new RuntimeException('MongoDB index direction must be int, float or string.'),
            };
        }

        return $normalized;
    }

    protected function normalizeIndexDirection(string $direction): int|string
    {
        return match (strtolower(trim($direction))) {
            '1', 'asc', 'ascending' => 1,
            '-1', 'desc', 'descending' => -1,
            'text', 'hashed', '2dsphere', '2d', 'geoHaystack' => $direction,
            default => throw new RuntimeException("Unsupported MongoDB index direction [{$direction}]."),
        };
    }

    protected function generateIndexName(array $keys): string
    {
        $parts = [];
        foreach ($keys as $field => $direction) {
            $parts[] = $field . '_' . $direction;
        }

        return implode('_', $parts);
    }

    public function normalizeDocument(mixed $document): mixed
    {
        if (is_array($document)) {
            $normalized = [];
            foreach ($document as $key => $value) {
                $normalized[$key] = $this->normalizeDocument($value);
            }
            return $normalized;
        }

        if (is_object($document)) {
            if ($document instanceof \MongoDB\BSON\ObjectId) {
                return (string) $document;
            }

            if ($document instanceof \MongoDB\BSON\UTCDateTime) {
                return $document->toDateTime()->format('Y-m-d H:i:s');
            }

            if ($document instanceof \MongoDB\BSON\Decimal128) {
                return (string) $document;
            }

            return $this->normalizeDocument(get_object_vars($document));
        }

        return $document;
    }

    protected function normalizeIdentifier(mixed $id): string
    {
        if ($id instanceof \MongoDB\BSON\ObjectId) {
            return (string) $id;
        }

        return (string) $id;
    }
}
