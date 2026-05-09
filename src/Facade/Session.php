<?php

namespace Anon\Core\Facade;

/**
 * Session Facade类
 * 
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static bool has(string $key)
 * @method static void delete(string $key)
 * @method static void clear()
 * @method static void destroy()
 * @method static string getId()
 * @method static bool regenerateId(bool $deleteOldSession = true)
 */
class Session extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'session';
    }
}