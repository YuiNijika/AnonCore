<?php

namespace Anon\Core\Database\Mongo;

class Collection
{
    public function __construct(
        protected Connection $connection,
        protected string $name
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function exists(): bool
    {
        return $this->connection->hasCollection($this->name);
    }

    public function create(array $options = []): self
    {
        $this->connection->createCollection($this->name, $options);

        return $this;
    }

    public function drop(): bool
    {
        return $this->connection->dropCollection($this->name);
    }

    public function index(array $keys, array $options = []): string
    {
        return $this->connection->createIndex($this->name, $keys, $options);
    }

    public function unique(array $keys, array $options = []): string
    {
        return $this->index($keys, array_merge($options, ['unique' => true]));
    }

    public function text(array|string $fields, array $options = []): string
    {
        $fields = is_array($fields) ? $fields : [$fields];
        $keys = [];

        foreach ($fields as $field) {
            $keys[$field] = 'text';
        }

        return $this->index($keys, $options);
    }

    public function ttl(string $field, int $seconds, array $options = []): string
    {
        return $this->index(
            [$field => 1],
            array_merge($options, ['expireAfterSeconds' => $seconds])
        );
    }

    public function hashed(string $field, array $options = []): string
    {
        return $this->index([$field => 'hashed'], $options);
    }

    public function dropIndex(string|array $index): bool
    {
        return $this->connection->dropIndex($this->name, $index);
    }

    public function indexes(): array
    {
        return $this->connection->listIndexes($this->name);
    }

    public function validator(array $jsonSchema, string $validationLevel = 'strict', string $validationAction = 'error'): self
    {
        $this->connection->modifyCollection($this->name, [
            'validator' => ['$jsonSchema' => $jsonSchema],
            'validationLevel' => $validationLevel,
            'validationAction' => $validationAction,
        ]);

        return $this;
    }
}
