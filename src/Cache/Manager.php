<?php

namespace Anon\Core\Cache;

use Exception;
use Anon\Core\Facade\Env;

class Manager implements Contract
{
    /**
     * @var array 已实例化的缓存驱动池
     */
    protected array $stores = [];

    /**
     * @var string 默认缓存驱动
     */
    protected string $defaultDriver;

    public function __construct()
    {
        $this->defaultDriver = Env::get('CACHE_DRIVER', 'file');
    }

    /**
     * 切换到指定的缓存驱动
     * @param string|null $name 驱动名称
     * @return Contract
     */
    public function store(?string $name = null): Contract
    {
        $name = $name ?: $this->defaultDriver;

        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->resolve($name);
        }

        return $this->stores[$name];
    }

    /**
     * 解析并实例化对应的缓存驱动
     */
    protected function resolve(string $name): Contract
    {
        switch ($name) {
            case 'file':
                return new File();
            case 'redis':
                return new Redis();
            default:
                throw new Exception("Cache driver [{$name}] is not supported.");
        }
    }

    // ------------------------------------------------------------------------
    // 以下是代理到默认驱动的方法实现
    // ------------------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store()->set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->store()->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->store()->delete($key);
    }

    public function clear(): bool
    {
        return $this->store()->clear();
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->store()->increment($key, $value);
    }
}
