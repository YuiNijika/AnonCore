<?php

namespace Anon\Core\Exception;

use Exception;
use Throwable;

/**
 * 框架异常基类：业务可按类型捕获，Handler 仍按 Throwable 统一渲染。
 *
 * 位于 Exception 命名空间内，类名不再重复 Exception 后缀。
 */
class Base extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}