<?php

namespace Anon\Core\Database\Relations;

use Anon\Core\Database\Model;
use Anon\Core\Database\Model\QueryBuilder as ModelQueryBuilder;
use Anon\Core\Database\Mongo\ModelQueryBuilder as MongoModelQueryBuilder;
use DateTimeInterface;
use Stringable;

abstract class Relation
{
    protected Model $parent;
    protected string $related;

    public function __construct(Model $parent, string $related)
    {
        $this->parent = $parent;
        $this->related = $related;
    }

    abstract public function getResults(): mixed;

    /**
     * @param array<int, Model> $models
     */
    abstract public function eagerLoad(array $models, string $relation): void;

    protected function newQuery(): ModelQueryBuilder|MongoModelQueryBuilder
    {
        /** @var class-string<Model> $related */
        $related = $this->related;
        return $related::query();
    }

    protected function newRelatedInstance(): Model
    {
        $class = $this->related;
        return new $class();
    }

    protected function normalizeDictionaryKey(mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }
}
