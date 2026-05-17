<?php

namespace Anon\Core\Database\Relations;

use Anon\Core\Database\Model;

class BelongsTo extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        protected string $foreignKey,
        protected string $ownerKey
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): mixed
    {
        $value = $this->parent->getAttribute($this->foreignKey);
        if ($value === null) {
            return null;
        }

        return $this->newQuery()->where($this->ownerKey, $value)->first();
    }

    public function eagerLoad(array $models, string $relation): void
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->getAttribute($this->foreignKey);
            if ($value !== null) {
                $keys[] = $value;
            }
        }

        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relation, null);
            }
            return;
        }

        $results = $this->newQuery()->whereIn($this->ownerKey, $keys)->get();
        $dictionary = [];
        foreach ($results as $result) {
            $dictionary[$result->getAttribute($this->ownerKey)] = $result;
        }

        foreach ($models as $model) {
            $model->setRelation($relation, $dictionary[$model->getAttribute($this->foreignKey)] ?? null);
        }
    }
}
