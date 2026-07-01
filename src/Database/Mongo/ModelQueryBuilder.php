<?php

namespace Anon\Core\Database\Mongo;

use Anon\Core\Database\Model;

class ModelQueryBuilder extends QueryBuilder
{
    protected string $modelClass;
    protected array $eagerLoads = [];
    protected bool $softDeleteEnabled = false;
    protected string $deletedAtColumn = 'deleted_at';
    protected bool $includeTrashed = false;
    protected bool $onlyTrashed = false;
    protected bool $softDeleteScopeApplied = false;

    public function __construct(Connection $connection, string $modelClass)
    {
        parent::__construct($connection);
        $this->modelClass = $modelClass;
    }

    protected function hydrate(array $records): array
    {
        $models = [];
        $class = $this->modelClass;

        foreach ($records as $record) {
            $model = new $class();
            $model->setRawAttributes($record);
            $model->exists = true;
            $models[] = $model;
        }

        return $models;
    }

    public function with(array|string $relations): self
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        $this->eagerLoads = array_values(array_unique(array_merge($this->eagerLoads, $relations)));

        return $this;
    }

    public function enableSoftDelete(string $deletedAtColumn = 'deleted_at'): self
    {
        $this->softDeleteEnabled = true;
        $this->deletedAtColumn = $deletedAtColumn;

        return $this;
    }

    public function withTrashed(): self
    {
        $this->includeTrashed = true;
        $this->onlyTrashed = false;

        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->onlyTrashed = true;
        $this->includeTrashed = false;

        return $this;
    }

    protected function eagerLoadRelations(array $models): array
    {
        if ($models === [] || $this->eagerLoads === []) {
            return $models;
        }

        foreach ($this->eagerLoads as $relation) {
            $firstModel = $models[0];
            if (!method_exists($firstModel, $relation)) {
                continue;
            }

            $relationInstance = $firstModel->$relation();
            if ($relationInstance instanceof \Anon\Core\Database\Relations\Relation) {
                $relationInstance->eagerLoad($models, $relation);
            }
        }

        return $models;
    }

    protected function applySoftDeleteScope(): void
    {
        if (!$this->softDeleteEnabled || $this->softDeleteScopeApplied) {
            return;
        }

        if ($this->onlyTrashed) {
            parent::whereNotNull($this->deletedAtColumn);
        } elseif (!$this->includeTrashed) {
            parent::whereNull($this->deletedAtColumn);
        }

        $this->softDeleteScopeApplied = true;
    }

    public function get(): array
    {
        $this->applySoftDeleteScope();

        return $this->eagerLoadRelations($this->hydrate(parent::get()));
    }

    public function first(): array|Model|null
    {
        $this->applySoftDeleteScope();
        $this->limit(1);
        $records = parent::get();
        $models = $this->eagerLoadRelations($this->hydrate($records));

        return $models[0] ?? null;
    }

    public function paginate(int $perPage = 15, ?int $current = null): array
    {
        $this->applySoftDeleteScope();
        $result = parent::paginate($perPage, $current);
        $result['data'] = $this->eagerLoadRelations($this->hydrate($result['data']));

        return $result;
    }

    public function aggregateModels(array $stages = [], bool $includeQueryOptions = true): array
    {
        $this->applySoftDeleteScope();
        $records = parent::aggregatePipeline($stages, $includeQueryOptions);

        return $this->eagerLoadRelations($this->hydrate($records));
    }

    public function aggregatePaginate(array $stages, int $perPage = 15, ?int $current = null, bool $hydrateModels = false): array
    {
        $this->applySoftDeleteScope();

        if ($current === null) {
            $current = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        }
        if ($current < 1) {
            $current = 1;
        }

        $countQuery = clone $this;
        $countRows = $countQuery->aggregatePipeline(array_merge($stages, [['$count' => 'count']]), false);
        $total = (int) ($countRows[0]['count'] ?? 0);

        $offset = ($current - 1) * $perPage;

        $dataQuery = clone $this;
        $dataQuery->limit($perPage, $offset);
        $records = $dataQuery->aggregatePipeline($stages, true);

        if ($hydrateModels) {
            $records = $this->eagerLoadRelations($this->hydrate($records));
        }

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $current,
            'last_page' => (int) ceil($total / $perPage),
            'data' => $records,
        ];
    }

    public function aggregate(string $function, string $column = '*'): mixed
    {
        $this->applySoftDeleteScope();

        return parent::aggregate($function, $column);
    }

    public function update(array $values): int
    {
        $this->applySoftDeleteScope();

        return parent::update($values);
    }

    public function exists(): bool
    {
        $this->applySoftDeleteScope();

        return parent::exists();
    }

    public function delete(): int
    {
        $this->applySoftDeleteScope();

        if ($this->softDeleteEnabled && !$this->includeTrashed && !$this->onlyTrashed) {
            return parent::update([$this->deletedAtColumn => date('Y-m-d H:i:s')]);
        }

        return parent::delete();
    }

    public function cursor(): \Generator
    {
        $this->applySoftDeleteScope();
        $generator = parent::cursor();

        foreach ($generator as $record) {
            $models = $this->eagerLoadRelations($this->hydrate([$record]));
            yield $models[0];
        }
    }

    public function forceDelete(): int
    {
        return parent::delete();
    }

    public function restore(): int
    {
        if (!$this->softDeleteEnabled) {
            return 0;
        }

        $this->withTrashed();

        return parent::update([$this->deletedAtColumn => null]);
    }
}
