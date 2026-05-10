<?php

namespace Anon\Core\Database;

class ModelQueryBuilder extends QueryBuilder
{
    /**
     * @var string 模型类名
     */
    protected string $modelClass;

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
            $model = new $class($record);
            $model->exists = true;
            $models[] = $model;
        }
        return $models;
    }

    /**
     * 执行查询并获取所有结果
     */
    public function get(): array
    {
        $records = parent::get();
        return $this->hydrate($records);
    }

    /**
     * 执行查询并获取第一条结果
     */
    public function first(): array|Model|null
    {
        $this->limit(1);
        $records = parent::get();
        $models = $this->hydrate($records);
        return count($models) > 0 ? $models[0] : null;
    }

    /**
     * 分页查询
     */
    public function paginate(int $perPage = 15, ?int $current = null): array
    {
        $result = parent::paginate($perPage, $current);
        $result['data'] = $this->hydrate($result['data']);
        return $result;
    }
}
