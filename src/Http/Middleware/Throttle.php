<?php

namespace Anon\Core\Http\Middleware;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Facade\Cache;
use Anon\Core\Exception\HttpException;

class Throttle
{
    /**
     * 允许的最大请求次数
     */
    protected int $maxAttempts = 60;

    /**
     * 衰减时间（秒）
     */
    protected int $decaySeconds = 60;

    public function handle(Request $request, callable $next, ?string $maxAttempts = null, ?string $decaySeconds = null): Response
    {
        $maxAttempts = $maxAttempts !== null ? (int)$maxAttempts : $this->maxAttempts;
        $decaySeconds = $decaySeconds !== null ? (int)$decaySeconds : $this->decaySeconds;

        $key = $this->resolveRequestSignature($request);

        if ($this->tooManyAttempts($key, $maxAttempts)) {
            throw new HttpException(429, 'Too Many Requests');
        }

        $this->hit($key, $decaySeconds);

        /** @var Response $response */
        $response = $next($request);

        // 可以在响应头中加入 X-RateLimit 等信息
        $response->withHeader('X-RateLimit-Limit', (string)$maxAttempts);
        $response->withHeader('X-RateLimit-Remaining', (string)max(0, $maxAttempts - Cache::get($key, 0)));

        return $response;
    }

    /**
     * 解析请求签名作为缓存键
     */
    protected function resolveRequestSignature(Request $request): string
    {
        return 'throttle:' . sha1($request->method() . '|' . $request->uri() . '|' . $request->ip());
    }

    /**
     * 判断是否超过限制
     */
    protected function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $attempts = (int)Cache::get($key, 0);
        return $attempts >= $maxAttempts;
    }

    /**
     * 增加访问次数
     */
    protected function hit(string $key, int $decaySeconds): void
    {
        if (!Cache::has($key)) {
            Cache::set($key, 1, $decaySeconds);
        } else {
            Cache::increment($key);
        }
    }
}
