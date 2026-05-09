<?php

namespace Anon\Core\Routing;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Foundation\App;

class Router
{ 
    /**
     * @var RouteItem[][] 存储所有注册的路由 [method => [uri => RouteItem]]
     */
    protected array $routes = [];

    /**
     * @var string 当前正在解析的路由组前缀
     */
    protected string $groupPrefix = '';

    /**
     * @var array 当前正在解析的路由组中间件
     */
    protected array $groupMiddlewares = [];

    /**
     * 注册一个GET路由
     * @param string $uri 路由路径
     * @param callable|array|string $action 闭包函数或控制器动作
     * @return RouteItem
     */
    public function get(string $uri, mixed $action): RouteItem
    {
        return $this->addRoute('GET', $uri, $action);
    }

    /**
     * 注册一个POST路由
     * @param string $uri 路由路径
     * @param callable|array|string $action 闭包函数或控制器动作
     * @return RouteItem
     */
    public function post(string $uri, mixed $action): RouteItem
    {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * 注册一个PUT路由
     * @param string $uri 路由路径
     * @param callable|array|string $action 闭包函数或控制器动作
     * @return RouteItem
     */
    public function put(string $uri, mixed $action): RouteItem
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * 注册一个DELETE路由
     * @param string $uri 路由路径
     * @param callable|array|string $action 闭包函数或控制器动作
     * @return RouteItem
     */
    public function delete(string $uri, mixed $action): RouteItem
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * 注册一个支持任意请求方法的路由
     * @param string $uri 路由路径
     * @param callable|array|string $action 闭包函数或控制器动作
     */
    public function any(string $uri, mixed $action): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        foreach ($methods as $method) {
            $this->addRoute($method, $uri, $action);
        }
    }

    /**
     * @var array 路由组前缀堆栈
     */
    protected array $groupPrefixStack = [];

    /**
     * @var array 路由组中间件堆栈
     */
    protected array $groupMiddlewareStack = [];

    /**
     * @var RouteItem[] 暂存刚注册的单个路由, 用于后置链式调用
     */
    protected array $lastRouteItems = [];

    /**
     * 为当前正在创建的路由或路由组绑定中间件
     */
    public function middleware(array|string $middleware): self
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        // 如果存在刚注册的单个路由，则是后置调用 Route::get(...)->middleware(...)
        if (!empty($this->lastRouteItems)) {
            $lastItem = end($this->lastRouteItems);
            $lastItem->middleware($middleware);
            // 处理完后清空，避免污染后续调用
            $this->lastRouteItems = [];
            return $this;
        }

        // 否则是前置调用 Route::middleware(...)->group(...)
        $this->groupMiddlewareStack[] = $middleware;
        return $this;
    }

    /**
     * 注册路由组
     * 使用堆栈管理上下文
     * @param string|array $attributes 路由组属性 (如 prefix, middleware)
     * @param callable $callback 组内路由闭包
     * @return $this
     */
    public function group(string|array $attributes, callable $callback): self
    {
        // 组调用开始前，清空暂存的单个路由记录，防止交叉污染
        $this->lastRouteItems = [];

        if (is_string($attributes)) {
            $attributes = ['prefix' => $attributes];
        }

        // 解析并入栈
        $prefix = $attributes['prefix'] ?? '';
        $middleware = $attributes['middleware'] ?? [];
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        // 提取通过 middleware() 暂存的中间件
        if (!empty($this->groupMiddlewareStack)) {
            $pendingMiddlewares = array_pop($this->groupMiddlewareStack);
            $middleware = array_merge($pendingMiddlewares, $middleware);
        }

        $oldPrefix = $this->groupPrefix;
        $oldMiddlewares = $this->groupMiddlewares;

        $this->groupPrefix = $this->groupPrefix . '/' . trim($prefix, '/');
        if ($this->groupPrefix === '/') {
            $this->groupPrefix = '';
        }
        $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middleware);

        // 触发闭包注册组内路由
        call_user_func($callback, $this);

        // 组调用结束后，同样清空暂存记录
        $this->lastRouteItems = [];

        // 出栈恢复状态
        $this->groupPrefix = $oldPrefix;
        $this->groupMiddlewares = $oldMiddlewares;

        return $this;
    }

    /**
     * 内部添加路由方法
     * @param string $method 请求方法
     * @param string $uri 路由路径
     * @param mixed $action 执行动作
     * @return RouteItem
     */
    protected function addRoute(string $method, string $uri, mixed $action): RouteItem
    {
        // 附加路由组前缀
        $uri = $this->groupPrefix . '/' . ltrim($uri, '/');
        // 规范化URI格式，保留根路径单斜杠
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        $routeItem = new RouteItem($method, $uri, $action);
        
        // 预编译动态路由正则以提升性能
        if (str_contains($uri, '{')) {
            $routeItem->pattern = '#^' . preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<\1>[^/]+)', $uri) . '$#';
        }

        // 若存在组中间件，则统一将其附加至该路由
        if (!empty($this->groupMiddlewares)) {
            $routeItem->middleware($this->groupMiddlewares);
        }

        $this->routes[$method][$uri] = $routeItem;
        
        // 暂存最后一次注册的路由，供后置 ->middleware() 调用
        $this->lastRouteItems[] = $routeItem;
        
        return $routeItem;
    }

    /**
     * 根据请求分发路由
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri = $request->uri();
        $uri = '/' . ltrim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // 查找精确匹配的路由
        if (isset($this->routes[$method][$uri])) {
            $routeItem = $this->routes[$method][$uri];
            return $this->runRouteThroughMiddleware($routeItem, $request);
        }

        // 查找动态匹配路由
        if (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $routeUri => $routeItem) {
                if ($routeItem->pattern !== null) {
                    if (preg_match($routeItem->pattern, $uri, $matches)) {
                        // 提取命名参数
                        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                        $request->setRouteParams($params);
                        return $this->runRouteThroughMiddleware($routeItem, $request);
                    }
                }
            }
        }

        throw new \Anon\Core\Exception\HttpException(404, "Route not found: {$method} {$uri}");
    }

    /**
     * 穿过中间件栈执行路由
     * @param RouteItem $routeItem
     * @param Request $request
     * @return Response
     */
    protected function runRouteThroughMiddleware(RouteItem $routeItem, Request $request): Response
    {
        $middlewares = $routeItem->middlewares;
        $action = $routeItem->action;

        // 构建洋葱模型闭包执行栈
        $next = function ($request) use ($action) {
            return $this->runAction($action, $request);
        };

        // 倒序遍历包装中间件，保证洋葱外层先执行
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $middlewareClass = $middlewares[$i];
            if (class_exists($middlewareClass)) {
                $middlewareInstance = new $middlewareClass();
                if (method_exists($middlewareInstance, 'handle')) {
                    $next = function ($request) use ($middlewareInstance, $next) {
                        return call_user_func([$middlewareInstance, 'handle'], $request, $next);
                    };
                }
            }
        }

        // 触发调用栈
        return call_user_func($next, $request);
    }

    /**
     * 执行路由动作
     * @param mixed $action
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    protected function runAction(mixed $action, Request $request): Response
    {
        $result = null;
        $app = App::getInstance();

        // 提取路由参数用于注入
        $routeParams = $request->getRouteParams() ?? [];
        $injectParams = array_merge(['request' => $request], $routeParams);

        // 1. 如果是闭包函数
        if (is_callable($action)) {
            // 利用反射自动注入参数
            $reflect = new \ReflectionFunction($action);
            $args = $this->resolveMethodDependencies($reflect, $injectParams, $app);
            $result = call_user_func_array($action, $args);
        } 
        // 2. 如果是数组形式的控制器方法调用
        else if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;
            if (class_exists($class)) {
                $controller = $app->make($class);
                if (method_exists($controller, $method)) {
                    $reflect = new \ReflectionMethod($controller, $method);
                    $args = $this->resolveMethodDependencies($reflect, $injectParams, $app);
                    $result = call_user_func_array([$controller, $method], $args);
                } else {
                    throw new \Exception("Method {$method} not found in controller {$class}");
                }
            } else {
                throw new \Exception("Controller class {$class} not found");
            }
        }
        // 3. 如果是字符串形式的控制器调用
        else if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action);
            $class = "Anon\\Controller\\" . ltrim($class, '\\');
            if (class_exists($class)) {
                $controller = $app->make($class);
                if (method_exists($controller, $method)) {
                    $reflect = new \ReflectionMethod($controller, $method);
                    $args = $this->resolveMethodDependencies($reflect, $injectParams, $app);
                    $result = call_user_func_array([$controller, $method], $args);
                } else {
                    throw new \Exception("Method {$method} not found in controller {$class}");
                }
            } else {
                throw new \Exception("Controller class {$class} not found");
            }
        } else {
            throw new \Exception("Invalid route action type");
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return Response::success($result);
        }
        return Response::json($result);
    }

    /**
     * 自动解析方法参数依赖（DI）
     */
    protected function resolveMethodDependencies(\ReflectionFunctionAbstract $reflect, array $routeParams, App $app): array
    {
        $args = [];
        $params = $reflect->getParameters();
        
        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 如果参数有类/接口类型的提示，优先使用容器解析注入
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // 特殊处理 Request，避免被同名路由参数覆盖
                if ($type->getName() === Request::class && isset($routeParams['request']) && $routeParams['request'] instanceof Request) {
                    $args[] = $routeParams['request'];
                } else {
                    $args[] = $app->make($type->getName());
                }
            }
            // 然后尝试使用路由动态参数匹配
            elseif (array_key_exists($name, $routeParams) && is_scalar($routeParams[$name])) {
                $args[] = $routeParams[$name];
            } 
            // 若存在默认值则使用默认值
            elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            }
            // 无法匹配时注入 null
            else {
                $args[] = null;
            }
        }
        return $args;
    }
}
