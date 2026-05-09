<?php

namespace Anon\Core\Facade;

/**
 * Env Facade类
 * 
 * @method static void load(string $file)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 */
class Env extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'env';
    }
}