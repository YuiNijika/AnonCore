<?php

namespace Anon\Core\Http\Middleware;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Facade\Cache;
use Anon\Core\Exception\Http;

class Throttle
{
    /**
     * 允许的最大请求次数
     */
    protected int $maxAttempts = 60;

    /**
     * 衰减时间
     */
    protected int $decaySeconds = 60;

    public function handle(Request $request, callable $next, ?string $maxAttempts = null, ?string $decaySeconds = null): Response
    {
        $maxAttempts = $maxAttempts !== null ? (int)$maxAttempts : $this->maxAttempts;
        $decaySeconds = $decaySeconds !== null ? (int)$decaySeconds : $this->decaySeconds;

        $key = $this->resolveRequestSignature($request);

        if ($this->tooManyAttempts($key, $maxAttempts)) {
            throw new Http(429, 'Too Many Requests');
        }

        $this->hit($key, $decaySeconds);

        /** @var Response $response */
        $response = $next($request);

        // 可以在响应头中加入 X-RateLimit 等信息
        $response->withHeader('X-RateLimit-Limit', (string)$maxAttempts);
        $response->withHeader('X-RateLimit-Remaining', (string)max(0, $maxAttempts - (int) Cache::get($key, 0)));

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
        $attempts = (int) Cache::get($key, 0);
        return $attempts >= $maxAttempts;
    }

    /**
     * 原子递增访问次数；首次创建键时设置 TTL。
     *
     * 使用 increment（Redis INCR / File 文件锁）避免 has+set 竞态。
     */
    protected function hit(string $key, int $decaySeconds): void
    {
        $attempts = Cache::increment($key);

        if ($attempts === false) {
            Cache::set($key, 1, $decaySeconds);
            return;
        }

        // 仅在首次从 0→1 时设置过期，避免并发下互相覆盖计数值
        if ((int) $attempts === 1 && $decaySeconds > 0) {
            $store = Cache::store();
            if (method_exists($store, 'expire')) {
                $store->expire($key, $decaySeconds);
            } else {
                // File 等驱动：带锁重写值+TTL；此时 attempts 必为 1
                Cache::set($key, 1, $decaySeconds);
            }
        }
    }
}