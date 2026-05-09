<?php

namespace Anon\Core\Facade;

/**
 * 路由Facade类
 * @method static \Anon\Core\Routing\RouteItem get(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem post(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem put(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem delete(string $uri, mixed $action)
 * @method static void any(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\Router group(string $prefix, callable $callback)
 * @method static \Anon\Core\Http\Response dispatch(\Anon\Core\Http\Request $request)
 */
class Route extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'router';
    }
}
