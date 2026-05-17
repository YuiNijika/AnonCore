<?php

namespace Anon\Core\Database\Relations;

use Anon\Core\Database\Model;
use Anon\Core\Database\ModelQueryBuilder;

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

    protected function newQuery(): ModelQueryBuilder
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
}
