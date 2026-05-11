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
    protected bool $allowCredentials = true;

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
        /** @var Response $response */
        $response = $next($request);

        $origin = $request->header('Origin');

        // 如果配置了具体的 Origin，或者允许所有 (*)
        if ($origin && (in_array('*', $this->allowedOrigins) || in_array($origin, $this->allowedOrigins))) {
            $allowedOrigin = in_array('*', $this->allowedOrigins) ? '*' : $origin;
            
            // 响应头设置
            $response->header('Access-Control-Allow-Origin', $allowedOrigin);
            $response->header('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
            $response->header('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
            
            if ($this->allowCredentials) {
                // Access-Control-Allow-Origin 不能为 * 当 allowCredentials 为 true 时
                // 所以我们强制设为具体的 origin
                $response->header('Access-Control-Allow-Origin', $origin);
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
            
            $response->header('Access-Control-Max-Age', (string)$this->maxAge);
        }

        return $response;
    }
}
