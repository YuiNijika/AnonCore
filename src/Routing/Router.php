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
     * 注册一个PATCH路由
     * @param string $uri 路由路径
     * @param callable|array|string $action 闭包函数或控制器动作
     * @return RouteItem
     */
    public function patch(string $uri, mixed $action): RouteItem
    {
        return $this->addRoute('PATCH', $uri, $action);
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
     * 注册一组常用资源路由
     *
     * GET    /resource         -> index
     * GET    /resource/{id}    -> show
     * POST   /resource         -> store
     * PUT    /resource/{id}    -> update
     * PATCH  /resource/{id}    -> update
     * DELETE /resource/{id}    -> delete
     *
     * @return RouteItem[]
     */
    public function resource(string $uri, mixed $controller, array $options = []): array
    {
        $uri = '/' . trim($uri, '/');
        $param = $this->resolveResourceParam($options);
        $detailUri = rtrim($uri, '/') . '/{' . $param . '}';
        $allowedActions = $this->resolveResourceActions($options);

        $definitions = [
            ['action' => 'index', 'method' => 'get', 'uri' => $uri],
            ['action' => 'show', 'method' => 'get', 'uri' => $detailUri],
            ['action' => 'store', 'method' => 'post', 'uri' => $uri],
            ['action' => 'update', 'method' => 'put', 'uri' => $detailUri],
            ['action' => 'update', 'method' => 'patch', 'uri' => $detailUri],
            ['action' => 'delete', 'method' => 'delete', 'uri' => $detailUri],
        ];

        $routes = [];
        foreach ($definitions as $definition) {
            if (!in_array($definition['action'], $allowedActions, true)) {
                continue;
            }

            $routes[] = $this->{$definition['method']}(
                $definition['uri'],
                $this->buildControllerAction($controller, $definition['action'])
            );
        }

        return $routes;
    }

    /**
     * @var array 当前正在解析的路由组前缀堆栈
     */
    protected array $groupPrefixStack = [];

    /**
     * @var array 当前正在解析的路由组中间件堆栈
     */
    protected array $groupMiddlewareStack = [];

    /**
     * @var array 暂存通过 middleware() 链式调用传入的前置中间件堆栈
     */
    protected array $pendingMiddlewareStack = [];

    /**
     * @var RouteItem[] 暂存刚注册的单个路由, 用于后置链式调用
     */
    protected array $lastRouteItems = [];

    /**
     * @var array 全局中间件
     */
    protected array $globalMiddlewares = [];

    /**
     * 导出可缓存的路由定义
     *
     * @return array<string, mixed>
     */
    public function exportForCache(): array
    {
        $routes = [];

        foreach ($this->routes as $method => $items) {
            foreach ($items as $uri => $routeItem) {
                $routes[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'pattern' => $routeItem->pattern,
                    'action' => $this->normalizeActionForCache($routeItem->action, $method, $uri),
                    'middlewares' => $routeItem->middlewares,
                ];
            }
        }

        return [
            'routes' => $routes,
            'global_middlewares' => $this->globalMiddlewares,
        ];
    }

    /**
     * 从缓存载入路由定义
     *
     * @param array<string, mixed> $payload
     */
    public function loadCachedRoutes(array $payload): void
    {
        $this->routes = [];
        $this->globalMiddlewares = is_array($payload['global_middlewares'] ?? null)
            ? $payload['global_middlewares']
            : [];

        foreach (($payload['routes'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $routeItem = new RouteItem(
                (string) ($item['method'] ?? 'GET'),
                (string) ($item['uri'] ?? '/'),
                $this->restoreActionFromCache($item['action'] ?? null)
            );

            $routeItem->pattern = isset($item['pattern']) ? (string) $item['pattern'] : null;
            $routeItem->middlewares = is_array($item['middlewares'] ?? null) ? $item['middlewares'] : [];

            $this->routes[$routeItem->method][$routeItem->uri] = $routeItem;
        }
    }

    /**
     * 注册全局中间件
     * @param array|string $middleware
     * @return $this
     */
    public function globalMiddleware(array|string $middleware): self
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        $this->globalMiddlewares = array_merge($this->globalMiddlewares, $middleware);
        return $this;
    }

    /**
     * 规范化动作用于缓存
     */
    protected function normalizeActionForCache(mixed $action, string $method, string $uri): array|string
    {
        if ($action instanceof \Closure) {
            throw new \RuntimeException("Unable to cache route [{$method} {$uri}] because closure actions are not supported.");
        }

        if (is_string($action)) {
            return $action;
        }

        if (is_array($action) && count($action) === 2) {
            return [$action[0], $action[1]];
        }

        throw new \RuntimeException("Unable to cache route [{$method} {$uri}] because action type is not cacheable.");
    }

    /**
     * 还原缓存中的动作
     */
    protected function restoreActionFromCache(mixed $action): mixed
    {
        if (is_string($action)) {
            return $action;
        }

        if (is_array($action) && count($action) === 2) {
            return [$action[0], $action[1]];
        }

        throw new \RuntimeException('Invalid cached route action.');
    }

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

        // 否则是前置调用 Route::middleware()->group()
        $this->pendingMiddlewareStack[] = $middleware;
        return $this;
    }

    /**
     * 注册路由组
     * 支持任意深度嵌套，使用堆栈管理上下文
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

        // 提取通过 middleware() 暂存的前置中间件
        if (!empty($this->pendingMiddlewareStack)) {
            $pendingMiddlewares = array_pop($this->pendingMiddlewareStack);
            $middleware = array_merge($pendingMiddlewares, $middleware);
        }

        // 入栈当前状态
        array_push($this->groupPrefixStack, $this->groupPrefix);
        array_push($this->groupMiddlewareStack, $this->groupMiddlewares);

        // 更新当前上下文
        $this->groupPrefix = $this->groupPrefix . '/' . trim($prefix, '/');
        if ($this->groupPrefix === '/') {
            $this->groupPrefix = '';
        }
        $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middleware);

        // 触发闭包注册组内路由
        call_user_func($callback, $this);

        // 组调用结束后，清空暂存记录
        $this->lastRouteItems = [];

        // 出栈恢复上级状态
        $this->groupPrefix = array_pop($this->groupPrefixStack);
        $this->groupMiddlewares = array_pop($this->groupMiddlewareStack);

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
        
        // 暂存最后一次注册的路由，供后置 middleware 调用
        $this->lastRouteItems[] = $routeItem;
        
        return $routeItem;
    }

    /**
     * 将资源控制器定义转换为具体动作
     */
    protected function buildControllerAction(mixed $controller, string $method): mixed
    {
        if (is_array($controller) && count($controller) === 2) {
            return [$controller[0], $method];
        }

        if (is_string($controller)) {
            $controller = trim($controller);
            if (str_contains($controller, '@')) {
                [$controller] = explode('@', $controller, 2);
            }

            if (class_exists($controller) || str_starts_with($controller, 'Anon\\Controller\\')) {
                return [$controller, $method];
            }

            return $controller . '@' . $method;
        }

        return [$controller, $method];
    }

    /**
     * 解析资源路由的参数名
     */
    protected function resolveResourceParam(array $options): string
    {
        $param = (string) ($options['param'] ?? 'id');

        if ($param === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $param)) {
            return 'id';
        }

        return $param;
    }

    /**
     * 解析资源路由允许的动作列表
     *
     * @return string[]
     */
    protected function resolveResourceActions(array $options): array
    {
        $available = ['index', 'show', 'store', 'update', 'delete'];
        $only = $this->normalizeResourceActionList($options['only'] ?? null);
        $except = $this->normalizeResourceActionList($options['except'] ?? null);

        if ($only !== []) {
            return array_values(array_intersect($available, $only));
        }

        if ($except !== []) {
            return array_values(array_diff($available, $except));
        }

        return $available;
    }

    /**
     * 规范化 only/except 动作列表
     *
     * @return string[]
     */
    protected function normalizeResourceActionList(mixed $actions): array
    {
        if (is_string($actions)) {
            $actions = explode(',', $actions);
        }

        if (!is_array($actions)) {
            return [];
        }

        $available = ['index', 'show', 'store', 'update', 'delete'];
        $normalized = [];

        foreach ($actions as $action) {
            $action = strtolower(trim((string) $action));
            if ($action !== '' && in_array($action, $available, true)) {
                $normalized[] = $action;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 根据请求分发路由
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function dispatch(Request $request): Response
    {
        // 构建全局中间件执行栈
        $next = function ($request) {
            return $this->findRouteAndExecute($request);
        };

        // 倒序遍历包装全局中间件，保证洋葱外层先执行
        for ($i = count($this->globalMiddlewares) - 1; $i >= 0; $i--) {
            $middlewareClass = $this->globalMiddlewares[$i];
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
     * 查找匹配的路由并执行其特定的中间件和动作
     */
    protected function findRouteAndExecute(Request $request): Response
    {
        $method = $request->method();
        $uri = $request->uri();
        $uri = '/' . ltrim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // 处理跨域预检请求的快捷放行 (如果开启了全局CORS但没显式注册OPTIONS路由)
        if ($method === 'OPTIONS') {
            if (!isset($this->routes['OPTIONS'][$uri])) {
                return Response::json([], 204);
            }
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
     * @var array 路由中间件实例化缓存
     */
    protected array $middlewareInstanceCache = [];

    /**
     * 穿过路由特定的中间件栈执行路由
     * @param RouteItem $routeItem
     * @param Request $request
     * @return Response
     */
    protected function runRouteThroughMiddleware(RouteItem $routeItem, Request $request): Response
    {
        $middlewares = $routeItem->middlewares;
        $action = $routeItem->action;

        // 构建洋葱模型闭包执行栈
        // 注意闭包必须通过引用或传递修改后的 $request 才能生效
        $next = function ($req) use ($action) {
            return $this->runAction($action, $req);
        };

        // 倒序遍历包装中间件，保证洋葱外层先执行
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $middlewareDef = $middlewares[$i];
            
            // 解析中间件参数
            $middlewareClass = $middlewareDef;
            $middlewareArgs = [];
            if (str_contains($middlewareDef, ':')) {
                [$middlewareClass, $argsStr] = explode(':', $middlewareDef, 2);
                $middlewareArgs = explode(',', $argsStr);
            }

            // 缓存中间件实例
            if (!isset($this->middlewareInstanceCache[$middlewareClass])) {
                if (class_exists($middlewareClass)) {
                    $this->middlewareInstanceCache[$middlewareClass] = new $middlewareClass();
                } else {
                    continue;
                }
            }
            
            $middlewareInstance = $this->middlewareInstanceCache[$middlewareClass];

            if (method_exists($middlewareInstance, 'handle')) {
                // 这里的 $next 捕获了外层或上一个的闭包引用
                $next = function ($req) use ($middlewareInstance, $next, $middlewareArgs) {
                    try {
                        return call_user_func_array([$middlewareInstance, 'handle'], array_merge([$req, $next], $middlewareArgs));
                    } catch (\Throwable $e) {
                        // 洋葱模型异常穿透必须支持拦截
                        throw $e;
                    }
                };
            }
        }

        // 触发调用栈
        return call_user_func($next, $request);
    }

    /**
     * @var array 控制器方法反射缓存
     */
    protected array $controllerReflectionCache = [];

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

        // 如果是闭包函数
        if (is_callable($action)) {
            // 利用反射自动注入参数
            $reflect = new \ReflectionFunction($action);
            $args = $this->resolveMethodDependencies($reflect, $injectParams, $app);
            $result = call_user_func_array($action, $args);
        } 
        // 如果是数组形式的控制器方法调用
        else if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;
            if (class_exists($class)) {
                $controller = $app->make($class);
                if (method_exists($controller, $method)) {
                    $cacheKey = $class . '::' . $method;
                    if (!isset($this->controllerReflectionCache[$cacheKey])) {
                        $this->controllerReflectionCache[$cacheKey] = new \ReflectionMethod($controller, $method);
                    }
                    $reflect = $this->controllerReflectionCache[$cacheKey];
                    $args = $this->resolveMethodDependencies($reflect, $injectParams, $app);
                    $result = call_user_func_array([$controller, $method], $args);
                } else {
                    throw new \Exception("Method {$method} not found in controller {$class}");
                }
            } else {
                throw new \Exception("Controller class {$class} not found");
            }
        }
        // 如果是字符串形式的控制器调用
        else if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action);
            $class = str_replace(['/', '\\'], '\\', trim($class));
            if (!str_starts_with($class, 'Anon\\Controller\\')) {
                $class = 'Anon\\Controller\\' . ltrim($class, '\\');
            }
            if (class_exists($class)) {
                $controller = $app->make($class);
                if (method_exists($controller, $method)) {
                    $cacheKey = $class . '::' . $method;
                    if (!isset($this->controllerReflectionCache[$cacheKey])) {
                        $this->controllerReflectionCache[$cacheKey] = new \ReflectionMethod($controller, $method);
                    }
                    $reflect = $this->controllerReflectionCache[$cacheKey];
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
     * 自动解析方法参数依赖
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
                $className = $type->getName();
                
                // 特殊处理 Request 及其子类
                if (is_a($className, Request::class, true)) {
                    // 如果参数类型是特定的 FormRequest，我们需要实例化它并执行验证
                    if ($className !== Request::class && is_subclass_of($className, \Anon\Core\Http\FormRequest::class)) {
                        /** @var \Anon\Core\Http\FormRequest $formRequest */
                        $formRequest = $app->make($className);
                        // 复制当前 request 的数据到 form request
                        $currentRequest = $routeParams['request'] ?? $app->make(Request::class);
                        $formRequest->cloneFrom($currentRequest);

                        // 验证
                        $formRequest->validateResolved();
                        $args[] = $formRequest;
                    } else {
                        // 普通 Request 注入
                        if (isset($routeParams['request']) && $routeParams['request'] instanceof Request) {
                            $args[] = $routeParams['request'];
                        } else {
                            $args[] = $app->make($className);
                        }
                    }
                } else {
                    $args[] = $app->make($className);
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
