<?php

namespace Anon\Core\Facade;

/**
 * Storage Facade类
 * 
 * @method static \Anon\Core\Storage\Contract disk(string|null $name = null)
 * @method static bool exists(string $path)
 * @method static string|false get(string $path)
 * @method static bool put(string $path, string $contents)
 * @method static bool delete(string $path)
 * @method static string url(string $path)
 */
class Storage extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'storage';
    }
}
