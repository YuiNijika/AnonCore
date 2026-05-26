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
        $traceId = $this->makeTraceId();
        $statusCode = 500;
        $message = $e->getMessage();
        $errors = null;
        $errorCode = 'INTERNAL_ERROR';

        if ($e instanceof Http) {
            $statusCode = $e->getStatusCode();
            $errors = $e->getData();
            $errorCode = $e->getErrorCode();
        } else {
            if (!$this->isDebug()) {
                $message = 'Internal Server Error';
            }
        }

        if ($message === '') {
            $message = $this->defaultMessage($statusCode);
        }

        $debug = [];
        if ($this->isDebug()) {
            $debug = [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }

        if ($statusCode >= 500) {
            Log::error([
                'trace_id' => $traceId,
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 'exception');
        }

        $hookResponses = Hook::trigger('exception_render', [
            'exception' => $e,
            'statusCode' => $statusCode,
            'code' => $errorCode,
            'message' => $message,
            'errors' => $errors,
            'trace_id' => $traceId,
            'debug' => $debug,
        ]);

        foreach ($hookResponses as $hookResponse) {
            if ($hookResponse instanceof Response) {
                $hookResponse->send();
                exit(1);
            }
        }

        Response::error($message, $statusCode, $errors, $errorCode, $traceId, $debug)->send();
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

    protected function makeTraceId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable) {
            return str_replace('.', '', uniqid('', true));
        }
    }

    protected function defaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Validation failed.',
            429 => 'Too Many Requests',
            default => $statusCode >= 500 ? 'Internal Server Error' : 'Error',
        };
    }
}
