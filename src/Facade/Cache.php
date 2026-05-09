<?php

namespace Anon\Core\Facade;

/**
 * Cache Facade类
 * 
 * @method static \Anon\Core\Cache\Contract store(string|null $name = null)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, int|null $ttl = null)
 * @method static bool has(string $key)
 * @method static bool delete(string $key)
 * @method static bool clear()
 */
class Cache extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cache';
    }
} 