<?php

namespace Anon\Core\Facade;

/**
 * 事件外观类 (Facade)
 * 
 * @method static void listen(string $event, callable|string|array $listener)
 * @method static array dispatch(string|object $event, mixed $payload = [])
 * @method static void forget(string $event)
 */
class Event extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'event';
    }
}