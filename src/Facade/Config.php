<?php

namespace Anon\Core\Facade;

/**
 * Config Facade类
 *
 * @method static void load(string $file)
 * @method static mixed get(?string $key = null, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static bool has(string $key)
 * @method static array all()
 */
class Config extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'config';
    }
}
