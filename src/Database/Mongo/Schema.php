<?php

namespace Anon\Core\Database\Mongo;

class Schema
{
    public function __construct(
        protected Connection $connection
    ) {
    }

    public function create(string $collection, ?callable $callback = null, array $options = []): Collection
    {
        $this->connection->createCollection($collection, $options);
        $blueprint = $this->table($collection);

        if ($callback !== null) {
            $callback($blueprint);
        }

        return $blueprint;
    }

    public function table(string $collection, ?callable $callback = null): Collection
    {
        $blueprint = new Collection($this->connection, $collection);

        if ($callback !== null) {
            $callback($blueprint);
        }

        return $blueprint;
    }

    public function hasCollection(string $collection): bool
    {
        return $this->connection->hasCollection($collection);
    }

    public function drop(string $collection): bool
    {
        return $this->connection->dropCollection($collection);
    }

    public function dropIfExists(string $collection): bool
    {
        return $this->drop($collection);
    }

    public function collections(): array
    {
        return $this->connection->listCollections();
    }
}
