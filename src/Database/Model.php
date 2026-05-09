<?php

namespace Anon\Core\Database;

use JsonSerializable;
use ArrayAccess;

abstract class Model implements JsonSerializable, ArrayAccess
{
    /**
     * @var string 模型对应的数据表名
     */
    protected string $table = '';

    /**
     * @var string 主键名
     */
    protected string $primaryKey = 'id';

    /**
     * @var bool 是否自动维护时间戳
     */
    protected bool $timestamps = true;

    /**
     * @var string 创建时间字段名
     */
    const CREATED_AT = 'created_at';

    /**
     * @var string 更新时间字段名
     */
    const UPDATED_AT = 'updated_at';

    /**
     * @var array 模型数据
     */
    protected array $attributes = [];

    /**
     * @var array 原始数据（用于脏数据检查）
     */
    protected array $original = [];

    /**
     * @var bool 是否已存在于数据库中
     */
    public bool $exists = false;

    /**
     * @var array 可批量赋值的字段
     */
    protected array $fillable = [];

    /**
     * @var array 不可批量赋值的字段
     */
    protected array $guarded = ['*'];

    /**
     * 构造函数
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * 填充模型属性
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * 判断字段是否可批量赋值
     */
    protected function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable)) {
            return true;
        }

        if ($this->guarded === ['*']) {
            return false;
        }

        return !in_array($key, $this->guarded);
    }

    /**
     * 获取数据表名
     */
    public function getTable(): string
    {
        if (empty($this->table)) {
            // 默认表名为类名的蛇形复数 (这里做简单小写处理)
            $class = explode('\\', static::class);
            $this->table = strtolower(end($class)) . 's';
        }
        return $this->table;
    }

    /**
     * 获取查询构建器实例
     */
    public static function query(): ModelQueryBuilder
    {
        $instance = new static;
        $connection = \Anon\Core\Foundation\App::getInstance()->make('db');
        
        $builder = new ModelQueryBuilder($connection, static::class);
        $builder->table($instance->getTable());
        
        return $builder;
    }

    /**
     * 根据主键查找模型
     */
    public static function find(mixed $id): ?static
    {
        $instance = new static;
        return static::query()->where($instance->primaryKey, $id)->first();
    }

    /**
     * 获取所有模型
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * 创建新模型
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * 根据主键删除模型
     */
    public static function destroy(mixed $ids): int
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $instance = new static;
        return static::query()->whereIn($instance->primaryKey, $ids)->delete();
    }

    /**
     * 静态调用查询构建器方法 (ActiveRecord)
     */
    public static function __callStatic($method, $parameters)
    {
        return (new static)->query()->$method(...$parameters);
    }

    /**
     * 获取属性
     */
    public function getAttribute(string $key)
    {
        // 检查是否有 Getter (Accessors)
        $method = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $method)) {
            return $this->$method($this->attributes[$key] ?? null);
        }

        return $this->attributes[$key] ?? null;
    }

    /**
     * 设置属性
     */
    public function setAttribute(string $key, $value): self
    {
        // 检查是否有 Setter (Mutators)
        $method = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $method)) {
            $this->$method($value);
        } else {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * 魔术方法：读取属性
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * 魔术方法：写入属性
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * 保存模型 (Insert or Update)
     */
    public function save(): bool
    {
        $query = $this->query();

        if ($this->timestamps) {
            $time = date('Y-m-d H:i:s');
            $this->setAttribute(static::UPDATED_AT, $time);
            if (!$this->exists) {
                $this->setAttribute(static::CREATED_AT, $time);
            }
        }

        if ($this->exists) {
            $saved = $query->where($this->primaryKey, $this->getAttribute($this->primaryKey))
                           ->update($this->attributes) > 0;
        } else {
            $id = $query->insert($this->attributes);
            if ($id) {
                $this->setAttribute($this->primaryKey, $id);
                $this->exists = true;
                $saved = true;
            } else {
                $saved = false;
            }
        }

        if ($saved) {
            $this->original = $this->attributes;
        }

        return $saved;
    }

    /**
     * 删除模型
     */
    public function delete(): bool
    {
        if (is_null($this->getAttribute($this->primaryKey))) {
            throw new \Exception('No primary key defined on model.');
        }

        if ($this->exists) {
            return $this->query()->where($this->primaryKey, $this->getAttribute($this->primaryKey))->delete() > 0;
        }

        return false;
    }

    /**
     * JSON 序列化实现
     */
    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }

    // --- ArrayAccess 接口实现 ---
    public function offsetExists(mixed $offset): bool { return isset($this->attributes[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->getAttribute($offset); }
    public function offsetSet(mixed $offset, mixed $value): void { $this->setAttribute($offset, $value); }
    public function offsetUnset(mixed $offset): void { unset($this->attributes[$offset]); }
}