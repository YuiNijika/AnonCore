<?php

namespace Anon\Core\Event;

use Anon\Core\Foundation\App;

class Dispatcher
{
    /**
     * @var array 注册的事件监听器
     */
    protected array $listeners = [];

    /**
     * 注册一个事件监听器
     * @param string $event 事件名称
     * @param callable|string|array $listener 监听器
     */
    public function listen(string $event, callable|string|array $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * 触发一个事件
     * @param string|object $event 事件名称或事件对象
     * @param mixed $payload 传递给监听器的载荷参数
     * @return array 所有监听器的返回结果集合
     */
    public function dispatch(string|object $event, mixed $payload = []): array
    {
        // 如果传入的是对象，则事件名称取其类名，载荷为其自身
        $eventName = is_object($event) ? get_class($event) : $event;
        
        if (is_object($event)) {
            $payload = [$event];
        } elseif (is_array($payload)) {
            // 如果是关联数组，将其整体作为一个参数包装，避免 PHP 8 命名参数解包错误
            if (empty($payload) || array_keys($payload) !== range(0, count($payload) - 1)) {
                $payload = [$payload];
            }
        } else {
            $payload = [$payload];
        }

        $responses = [];

        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners[$eventName] as $listener) {
                $response = $this->resolveListener($listener)($payload);
                $responses[] = $response;

                // 如果监听器返回了严格的 false，则停止后续监听器的传播
                if ($response === false) {
                    break;
                }
            }
        }

        return $responses;
    }

    /**
     * 解析监听器为闭包
     */
    protected function resolveListener(callable|string|array $listener): callable
    {
        if (is_callable($listener)) {
            return function ($payload) use ($listener) {
                return $listener(...$payload);
            };
        }

        // 解析 "Class@method" 或 "Class" 形式
        if (is_string($listener)) {
            return function ($payload) use ($listener) {
                $class = $listener;
                $method = 'handle';

                if (str_contains($listener, '@')) {
                    [$class, $method] = explode('@', $listener);
                }

                $instance = App::getInstance()->make($class);
                return $instance->$method(...$payload);
            };
        }

        // 解析 [Class, 'method'] 形式
        if (is_array($listener) && is_string($listener[0])) {
            return function ($payload) use ($listener) {
                $instance = App::getInstance()->make($listener[0]);
                $method = $listener[1] ?? 'handle';
                return $instance->$method(...$payload);
            };
        }

        return function () {};
    }

    /**
     * 移除指定事件的所有监听器
     */
    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /**
     * 注册一个事件监听器
     * @param string $hook 钩子名称
     * @param callable|string|array $behavior 行为/监听器
     */
    public function add(string $hook, callable|string|array $behavior): void
    {
        $this->listen($hook, $behavior);
    }

    /**
     * 触发一个事件/钩子
     * @param string|object $event 钩子名称或事件对象
     * @param mixed $payload 传递给钩子的载荷参数
     * @return array 所有行为的返回结果集合
     */
    public function trigger(string|object $event, mixed $payload = []): array
    {
        return $this->dispatch($event, $payload);
    }
}