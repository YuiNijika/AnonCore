<?php

namespace Anon\Core\Routing;

class RouteItem
{
    /**
     * @var string 请求方法
     */
    public string $method;

    /**
     * @var string 路由路径
     */
    public string $uri;

    /**
     * @var string|null 编译后的正则表达式
     */
    public ?string $pattern = null;

    /**
     * @var mixed 路由动作
     */
    public mixed $action;

    /**
     * @var array 路由绑定的中间件
     */
    public array $middlewares = [];

    public function __construct(string $method, string $uri, mixed $action)
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->action = $action;
    }

    /**
     * 为当前路由绑定中间件
     * @param string|array $middleware 中间件类名或类名数组
     * @return self
     */
    public function middleware(string|array $middleware): self
    {
        $middlewares = is_array($middleware) ? $middleware : [$middleware];
        $this->middlewares = array_merge($this->middlewares, $middlewares);
        return $this;
    }
}
