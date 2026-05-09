<?php

namespace Anon\Core\Facade;

/**
 * 钩子外观类
 * 
 * @method static void add(string $hook, callable|string|array $behavior)
 * @method static array trigger(string|object $event, mixed $payload = [])
 * @method static void listen(string $event, callable|string|array $listener)
 * @method static array dispatch(string|object $event, mixed $payload = [])
 */
class Hook extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'event';
    }
}