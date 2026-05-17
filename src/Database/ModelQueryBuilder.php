<?php

namespace Anon\Core\Database;

class ModelQueryBuilder extends QueryBuilder
{
    /**
     * @var string 模型类名
     */
    protected string $modelClass;

    /**
     * @var array<int, string>
     */
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

    /**
     * 将数组记录转换为模型实例
     */
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

    /**
     * 预加载指定关联
     */
    public function with(array|string $relations): self
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        $this->eagerLoads = array_values(array_unique(array_merge($this->eagerLoads, $relations)));
        return $this;
    }

    /**
     * 启用软删除全局作用域
     */
    public function enableSoftDelete(string $deletedAtColumn = 'deleted_at'): self
    {
        $this->softDeleteEnabled = true;
        $this->deletedAtColumn = $deletedAtColumn;
        return $this;
    }

    /**
     * 包含已软删除数据
     */
    public function withTrashed(): self
    {
        $this->includeTrashed = true;
        $this->onlyTrashed = false;
        return $this;
    }

    /**
     * 仅查询已软删除数据
     */
    public function onlyTrashed(): self
    {
        $this->onlyTrashed = true;
        $this->includeTrashed = false;
        return $this;
    }

    /**
     * @param array<int, Model> $models
     */
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

    /**
     * 执行查询并获取所有结果
     */
    public function get(): array
    {
        $this->applySoftDeleteScope();
        $records = parent::get();
        return $this->eagerLoadRelations($this->hydrate($records));
    }

    /**
     * 执行查询并获取第一条结果
     */
    public function first(): array|Model|null
    {
        $this->applySoftDeleteScope();
        $this->limit(1);
        $records = parent::get();
        $models = $this->eagerLoadRelations($this->hydrate($records));
        return count($models) > 0 ? $models[0] : null;
    }

    /**
     * 分页查询
     */
    public function paginate(int $perPage = 15, ?int $current = null): array
    {
        $this->applySoftDeleteScope();
        $result = parent::paginate($perPage, $current);
        $result['data'] = $this->eagerLoadRelations($this->hydrate($result['data']));
        return $result;
    }

    /**
     * 删除数据，启用软删除时默认写入删除时间
     */
    public function delete(): int
    {
        if ($this->softDeleteEnabled && !$this->includeTrashed && !$this->onlyTrashed) {
            return $this->update([$this->deletedAtColumn => date('Y-m-d H:i:s')]);
        }

        return parent::delete();
    }

    /**
     * 强制物理删除
     */
    public function forceDelete(): int
    {
        return parent::delete();
    }

    /**
     * 恢复软删除数据
     */
    public function restore(): int
    {
        if (!$this->softDeleteEnabled) {
            return 0;
        }

        $this->withTrashed();
        return $this->update([$this->deletedAtColumn => null]);
    }
}
