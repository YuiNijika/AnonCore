<?php

namespace Anon\Core\Database\Relations;

use Anon\Core\Database\Model;

class HasMany extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        protected string $foreignKey,
        protected string $localKey
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): mixed
    {
        $value = $this->parent->getAttribute($this->localKey);
        if ($value === null) {
            return [];
        }

        return $this->newQuery()->where($this->foreignKey, $value)->get();
    }

    public function eagerLoad(array $models, string $relation): void
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $this->normalizeDictionaryKey($model->getAttribute($this->localKey));
            if ($value !== null) {
                $keys[] = $value;
            }
        }

        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            foreach ($models as $model) {
                $model->setRelation($relation, []);
            }
            return;
        }

        $results = $this->newQuery()->whereIn($this->foreignKey, $keys)->get();
        $dictionary = [];
        foreach ($results as $result) {
            $key = $this->normalizeDictionaryKey($result->getAttribute($this->foreignKey));
            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        foreach ($models as $model) {
            $key = $this->normalizeDictionaryKey($model->getAttribute($this->localKey));
            $model->setRelation($relation, $key !== null ? ($dictionary[$key] ?? []) : []);
        }
    }
}
