<?php

namespace Anon\Core\Facade;

use Anon\Core\Foundation\App;
use RuntimeException;

abstract class Facade
{
    /**
     * 获取门面代理的组件名称
     * @return string
     * @throws RuntimeException
     */
    protected static function getFacadeAccessor(): string
    {
        throw new RuntimeException('Facade does not implement getFacadeAccessor method.');
    }

    /**
     * 从容器中解析底层实例
     */
    protected static function resolveFacadeInstance(string $name)
    {
        $app = App::getInstance();
        return $app->make($name);
    }

    /**
     * 静态调用转发
     */
    public static function __callStatic(string $method, array $args)
    {
        $instance = static::resolveFacadeInstance(static::getFacadeAccessor());

        if (!$instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return call_user_func_array([$instance, $method], $args);
    }
}