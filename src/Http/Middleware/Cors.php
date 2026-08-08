<?php

namespace Anon\Core\Http\Middleware;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

class Cors
{
    /**
     * 允许的源，默认全部
     */
    protected array $allowedOrigins = ['*'];

    /**
     * 允许的方法
     */
    protected array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];

    /**
     * 允许的请求头
     */
    protected array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'];

    /**
     * 是否允许携带凭证 (Cookies, Authorization headers等)
     */
    protected bool $allowCredentials = false;

    /**
     * 预检请求缓存时间
     */
    protected int $maxAge = 86400;

    /**
     * 执行中间件
     *
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');

        // OPTIONS 预检请求直接短路，不走路由
        if ($request->method() === 'OPTIONS' && $origin && $this->isOriginAllowed($origin)) {
            return $this->preflightResponse($origin);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($origin && $this->isOriginAllowed($origin)) {
            $this->applyCorsHeaders($response, $origin);
        }

        return $response;
    }

    /**
     * 判断指定 origin 是否在白名单中（* 表示允许所有）。
     */
    private function isOriginAllowed(string $origin): bool
    {
        return in_array('*', $this->allowedOrigins) || in_array($origin, $this->allowedOrigins);
    }

    /**
     * 生成 OPTIONS 预检响应（204 No Content + 全套 CORS 头）。
     */
    private function preflightResponse(string $origin): Response
    {
        $response = new Response('', 204);
        $this->applyCorsHeaders($response, $origin);

        return $response;
    }

    /**
     * 向 Response 写入 CORS 响应头。
     */
    private function applyCorsHeaders(Response $response, string $origin): void
    {
        $allowsWildcard = in_array('*', $this->allowedOrigins, true);
        if ($this->allowCredentials && $allowsWildcard) {
            throw new \InvalidArgumentException('CORS credentials require an explicit origin allowlist.');
        }

        $allowedOrigin = $allowsWildcard ? '*' : $origin;

        $response->withHeader('Vary', 'Origin');
        $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        $response->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

        if ($this->allowCredentials) {
            $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }
}
