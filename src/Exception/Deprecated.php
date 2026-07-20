<?php

namespace Anon\Core\Exception;

use Throwable;

/**
 * 已废弃且不再提供行为的 API / 配置。
 * 运行时直接中断，error_code 固定为 METHOD_DEPRECATED。
 */
class Deprecated extends Http
{
    public function __construct(
        string $message = 'This method is deprecated and no longer supported.',
        array $data = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(410, $message, $data, $previous, 'METHOD_DEPRECATED');
    }

    /**
     * @param class-string|string $method 方法名（可含 Class::method）
     */
    public static function method(string $method, string $replacement = ''): self
    {
        $label = str_contains($method, '::') || str_contains($method, '()')
            ? $method
            : "{$method}()";

        $message = $replacement !== ''
            ? "{$label} is deprecated and no longer supported. Use {$replacement} instead."
            : "{$label} is deprecated and no longer supported.";

        return new self($message);
    }

    /**
     * 配置项废弃（非方法，但同样不再兼容）。
     */
    public static function config(string $key, string $guidance = ''): self
    {
        $message = "Config [{$key}] is deprecated and no longer supported.";
        if ($guidance !== '') {
            $message .= ' ' . $guidance;
        }

        return new self($message, ['config' => $key]);
    }
}