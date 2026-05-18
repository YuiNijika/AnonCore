<?php

namespace Anon\Core\Database;

use JsonSerializable;
use ArrayAccess;
use Anon\Core\Support\Str;
use Anon\Core\Database\Relations\HasOne;
use Anon\Core\Database\Relations\HasMany;
use Anon\Core\Database\Relations\BelongsTo;
use Anon\Core\Database\Relations\Relation;

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
     * @var string 软删除字段名
     */
    const DELETED_AT = 'deleted_at';

    /**
     * @var array 模型的属性数据
     */
    protected array $attributes = [];

    /**
     * @var array 已加载的关联关系
     */
    protected array $relations = [];

    /**
     * @var array 原始数据，用于判断是否有修改
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
    protected array $guarded = [];

    /**
     * @var bool 是否启用软删除
     */
    protected bool $softDelete = false;

    /**
     * @var array 已注册的模型事件回调
     */
    protected static array $dispatcher = [];

    /**
     * 注册一个模型事件
     */
    public static function registerEvent(string $event, callable $callback): void
    {
        static::$dispatcher[static::class][$event][] = $callback;
    }

    /**
     * 触发模型事件
     */
    protected function fireEvent(string $event): bool
    {
        if (!isset(static::$dispatcher[static::class][$event])) {
            return true;
        }

        foreach (static::$dispatcher[static::class][$event] as $callback) {
            if ($callback($this) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 当模型被创建前
     */
    public static function creating(callable $callback): void
    {
        static::registerEvent('creating', $callback);
    }

    /**
     * 当模型被创建后
     */
    public static function created(callable $callback): void
    {
        static::registerEvent('created', $callback);
    }

    /**
     * 当模型被更新前
     */
    public static function updating(callable $callback): void
    {
        static::registerEvent('updating', $callback);
    }

    /**
     * 当模型被更新后
     */
    public static function updated(callable $callback): void
    {
        static::registerEvent('updated', $callback);
    }

    /**
     * 当模型被保存前（包含创建与更新）
     */
    public static function saving(callable $callback): void
    {
        static::registerEvent('saving', $callback);
    }

    /**
     * 当模型被保存后（包含创建与更新）
     */
    public static function saved(callable $callback): void
    {
        static::registerEvent('saved', $callback);
    }

    /**
     * 当模型被删除前
     */
    public static function deleting(callable $callback): void
    {
        static::registerEvent('deleting', $callback);
    }

    /**
     * 当模型被删除后
     */
    public static function deleted(callable $callback): void
    {
        static::registerEvent('deleted', $callback);
    }

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
        if ($instance->usesSoftDelete()) {
            $builder->enableSoftDelete(static::DELETED_AT);
        }
        
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
     * 直接设置原始属性，常用于数据库结果集回填
     */
    public function setRawAttributes(array $attributes, bool $syncOriginal = true): self
    {
        $this->attributes = $attributes;

        if ($syncOriginal) {
            $this->original = $attributes;
        }

        return $this;
    }

    /**
     * 设置属性
     */
    public function setAttribute(string $key, $value): self
    {
        // 检查是否有 Setter
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
        if (array_key_exists($key, $this->attributes)) {
            return $this->getAttribute($key);
        }

        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key)) {
            $relation = $this->$key();
            if ($relation instanceof Relation) {
                $results = $relation->getResults();
                $this->setRelation($key, $results);
                return $results;
            }
        }

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
     * 保存模型
     */
    public function save(): bool
    {
        if ($this->fireEvent('saving') === false) {
            return false;
        }

        $query = $this->query();

        if ($this->timestamps) {
            $time = date('Y-m-d H:i:s');
            $this->setAttribute(static::UPDATED_AT, $time);
            if (!$this->exists) {
                $this->setAttribute(static::CREATED_AT, $time);
            }
        }

        if ($this->exists) {
            if ($this->fireEvent('updating') === false) {
                return false;
            }

            $saved = $query->where($this->primaryKey, $this->getAttribute($this->primaryKey))
                           ->update($this->attributes) > 0;
            
            if ($saved) {
                $this->fireEvent('updated');
            }
        } else {
            if ($this->fireEvent('creating') === false) {
                return false;
            }

            $id = $query->insert($this->attributes);
            if ($id) {
                $this->setAttribute($this->primaryKey, $id);
                $this->exists = true;
                $saved = true;
                $this->fireEvent('created');
            } else {
                $saved = false;
            }
        }

        if ($saved) {
            $this->original = $this->attributes;
            $this->fireEvent('saved');
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

        if ($this->fireEvent('deleting') === false) {
            return false;
        }

        if ($this->exists) {
            if ($this->usesSoftDelete()) {
                $time = date('Y-m-d H:i:s');
                $deletedAt = static::DELETED_AT;
                $this->setAttribute($deletedAt, $time);
                $deleted = $this->query()
                    ->withTrashed()
                    ->where($this->primaryKey, $this->getAttribute($this->primaryKey))
                    ->update([$deletedAt => $time]) > 0;
            } else {
                $deleted = $this->query()->where($this->primaryKey, $this->getAttribute($this->primaryKey))->delete() > 0;
            }

            if ($deleted) {
                $this->fireEvent('deleted');
                return true;
            }
        }

        return false;
    }

    /**
     * 强制物理删除模型
     */
    public function forceDelete(): bool
    {
        if (is_null($this->getAttribute($this->primaryKey))) {
            throw new \Exception('No primary key defined on model.');
        }

        if ($this->exists) {
            return $this->query()
                ->withTrashed()
                ->where($this->primaryKey, $this->getAttribute($this->primaryKey))
                ->delete() > 0;
        }

        return false;
    }

    /**
     * 恢复被软删除的数据
     */
    public function restore(): bool
    {
        if (!$this->usesSoftDelete() || !$this->exists) {
            return false;
        }

        $deletedAt = static::DELETED_AT;
        $this->setAttribute($deletedAt, null);

        return $this->query()
            ->withTrashed()
            ->where($this->primaryKey, $this->getAttribute($this->primaryKey))
            ->update([$deletedAt => null]) > 0;
    }

    /**
     * 判断当前模型是否已被软删除
     */
    public function trashed(): bool
    {
        if (!$this->usesSoftDelete()) {
            return false;
        }

        return $this->getAttribute(static::DELETED_AT) !== null;
    }

    /**
     * 定义一对一关系
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?: Str::snake($this->getBaseClassName()) . '_' . $this->primaryKey;
        $localKey = $localKey ?: $this->primaryKey;

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * 定义一对多关系
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?: Str::snake($this->getBaseClassName()) . '_' . $this->primaryKey;
        $localKey = $localKey ?: $this->primaryKey;

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * 定义反向关联
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $ownerKey = $ownerKey ?: 'id';
        $foreignKey = $foreignKey ?: Str::snake($this->guessRelationName()) . '_' . $ownerKey;

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * 设置已加载的关系
     */
    public function setRelation(string $relation, mixed $value): self
    {
        $this->relations[$relation] = $value;
        return $this;
    }

    /**
     * 获取已加载关系
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    /**
     * 判断关系是否已加载
     */
    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    /**
     * 是否启用软删除
     */
    public function usesSoftDelete(): bool
    {
        return $this->softDelete;
    }

    /**
     * 获取基础类名
     */
    protected function getBaseClassName(): string
    {
        $class = explode('\\', static::class);
        return end($class);
    }

    /**
     * 推断当前关系方法名
     */
    protected function guessRelationName(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        return $trace[2]['function'] ?? $this->getBaseClassName();
    }

    /**
     * JSON 序列化实现
     */
    public function jsonSerialize(): mixed
    {
        return array_merge($this->attributes, $this->relations);
    }

    // --- ArrayAccess 接口实现 ---
    public function offsetExists(mixed $offset): bool { return isset($this->attributes[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->getAttribute($offset); }
    public function offsetSet(mixed $offset, mixed $value): void { $this->setAttribute($offset, $value); }
    public function offsetUnset(mixed $offset): void { unset($this->attributes[$offset]); }
}
