<?php

namespace Anon\Core\Facade;

/**
 * 日志Facade类
 *
 * @method static void info(string|array $message, string $type = 'app')
 * @method static void error(string|array $message, string $type = 'app')
 * @method static void debug(string|array $message, string $type = 'app')
 * @method static void warning(string|array $message, string $type = 'app')
 * @method static void log(string $level, string|array $message, string $type = 'app')
 * @method static \Anon\Core\Log\Manager setDebug(bool $isDebug)
 * @method static bool isDebug()
 * @method static \Anon\Core\Log\Manager setMinLevel(string $level)
 * @method static void flush()
 */
class Log extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'log';
    }
}