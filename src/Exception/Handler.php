<?php

namespace Anon\Core\Exception;

use Throwable;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Log;
use Anon\Core\Facade\Env;
use Anon\Core\Facade\Hook;
use Anon\Core\Http\Response;

class Handler
{
    /**
     * 渲染异常并发送 HTTP 响应
     */
    public function render(Throwable $e): void
    {
        $statusCode = 500;
        $responseData = null;
        $message = $e->getMessage();

        // 判断是否为 HTTP 异常
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            $responseData = $e->getData();
        } else {
            // 非 HTTP 异常在非 Debug 模式下隐藏真实错误信息
            if (!$this->isDebug()) {
                $message = "Internal Server Error";
            }
        }

        // 开发环境下返回详细的堆栈信息
        if ($this->isDebug()) {
            $responseData = [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
                'data'  => $responseData
            ];
        }

        // 只有 500 及以上的错误我们才记录到日志中
        if ($statusCode >= 500) {
            Log::error([
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ], 'exception');
        }

        // 触发异常渲染钩子，允许用户自定义响应输出结构（返回 Response 则直接发送）
        $hookResponses = Hook::trigger('exception_render', ['exception' => $e, 'statusCode' => $statusCode, 'message' => $message, 'data' => $responseData]);
        foreach ($hookResponses as $hookResponse) {
            if ($hookResponse instanceof Response) {
                $hookResponse->send();
                exit(1);
            }
        }

        // 统一输出 JSON 响应
        $response = Response::error($message, $statusCode, $responseData);
        $response->send();
        exit(1);
    }

    /**
     * 判断是否开启调试模式
     */
    protected function isDebug(): bool
    {
        if (defined('DEBUG_MODE')) {
            return (bool) DEBUG_MODE;
        }

        return (bool) Config::get('app.debug', Env::get('DEBUG_MODE', Env::get('APP_DEBUG', false)));
    }
}
