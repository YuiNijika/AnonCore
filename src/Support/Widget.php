<?php

namespace Anon\Core\Support;

use Exception;
use Anon\Core\Foundation\App;

class Widget
{
    /**
     * @var array 已注册的小部件集合
     */
    protected static array $widgets = [];

    /**
     * 注册一个小部件
     * 
     * @param string $name 小部件名称
     * @param string|callable $handler 小部件处理类名或闭包
     */
    public static function register(string $name, mixed $handler): void
    {
        self::$widgets[$name] = $handler;
    }

    /**
     * 判断小部件是否已注册
     */
    public static function has(string $name): bool
    {
        return isset(self::$widgets[$name]);
    }

    /**
     * 调用一个小部件
     * 
     * @param string $name 小部件名称
     * @param array $params 传递给小部件的参数
     * @return mixed 小部件返回的数据或视图字符串
     * @throws Exception
     */
    public static function call(string $name, array $params = []): mixed
    {
        if (!self::has($name)) {
            throw new Exception("Widget [{$name}] not found.");
        }

        $handler = self::$widgets[$name];

        // 如果是闭包，直接执行
        if (is_callable($handler)) {
            return call_user_func_array($handler, $params);
        }

        // 如果是类名，实例化并调用 render 方法
        if (is_string($handler) && class_exists($handler)) {
            $instance = App::getInstance()->make($handler);
            if (!method_exists($instance, 'render')) {
                throw new Exception("Widget class [{$handler}] must have a render() method.");
            }
            return call_user_func_array([$instance, 'render'], $params);
        }

        throw new Exception("Invalid handler for widget [{$name}].");
    }
}
