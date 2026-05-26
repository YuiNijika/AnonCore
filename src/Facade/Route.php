<?php

namespace Anon\Core\Facade;

/**
 * 路由Facade类
 * @method static \Anon\Core\Routing\RouteItem get(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem post(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem put(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem patch(string $uri, mixed $action)
 * @method static \Anon\Core\Routing\RouteItem delete(string $uri, mixed $action)
 * @method static void any(string $uri, mixed $action)
 * @method static array resource(string $uri, mixed $controller, array $options = [])
 * @method static \Anon\Core\Routing\Router version(string $version, string $base = '/api')
 * @method static \Anon\Core\Routing\Router bind(string $name, callable $resolver)
 * @method static \Anon\Core\Routing\Router model(string $name, string $class, string $key = 'id')
 * @method static \Anon\Core\Routing\Router group(string|array $attributes, callable $callback)
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
