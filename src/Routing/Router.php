<?php

namespace Anon\Core\Routing;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Foundation\App;
use Anon\Core\Action\Registry as ActionRegistry;

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
     * @var array<string, string> 中间件别名
     */
    protected array $middlewareAliases = [
        'auth' => \Anon\Core\Http\Middleware\Authenticate::class,
        'cors' => \Anon\Core\Http\Middleware\Cors::class,
        'throttle' => \Anon\Core\Http\Middleware\Throttle::class,
    ];

    /**
     * 获取所有注册的路由集合
     *
     * @return RouteItem[][]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

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
                    'meta' => $routeItem->meta(),
                ];
            }
        }

        $actions = [];
        $app = App::getInstance();
        if ($app) {
            try {
                $registry = $app->make('action.registry');
                if ($registry instanceof ActionRegistry) {
                    $actions = $registry->exportForCache();
                }
            } catch (\Throwable) {
                $actions = [];
            }
        }

        return [
            'routes' => $routes,
            'global_middlewares' => $this->globalMiddlewares,
            'actions' => $actions,
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
            $routeItem->fillMeta(is_array($item['meta'] ?? null) ? $item['meta'] : []);

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
     * 注册中间件别名
     */
    public function aliasMiddleware(string $alias, string $middleware): self
    {
        $alias = trim($alias);
        if ($alias !== '') {
            $this->middlewareAliases[$alias] = $middleware;
        }

        return $this;
    }

    /**
     * 批量注册中间件别名
     *
     * @param array<string, string> $aliases
     */
    public function aliasMiddlewares(array $aliases): self
    {
        foreach ($aliases as $alias => $middleware) {
            if (is_string($alias) && is_string($middleware)) {
                $this->aliasMiddleware($alias, $middleware);
            }
        }

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
        $next = function ($request) {
            return $this->findRouteAndExecute($request);
        };

        return $this->runMiddlewareStack($this->globalMiddlewares, $request, $next);
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

        throw new \Anon\Core\Exception\Http(404, "Route not found: {$method} {$uri}");
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
        $action = $routeItem->action;

        $next = function ($req) use ($action) {
            return $this->runAction($action, $req);
        };

        return $this->runMiddlewareStack($routeItem->middlewares, $request, $next);
    }

    /**
     * 执行中间件栈
     */
    public function runMiddlewareStack(array $middlewares, Request $request, callable $destination): Response
    {
        $next = $destination;

        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $resolved = $this->resolveMiddlewareDefinition($middlewares[$i]);
            if ($resolved === null) {
                continue;
            }

            [$middlewareClass, $middlewareArgs] = $resolved;

            if (!isset($this->middlewareInstanceCache[$middlewareClass])) {
                $this->middlewareInstanceCache[$middlewareClass] = new $middlewareClass();
            }

            $middlewareInstance = $this->middlewareInstanceCache[$middlewareClass];
            if (!method_exists($middlewareInstance, 'handle')) {
                continue;
            }

            $next = function ($req) use ($middlewareInstance, $next, $middlewareArgs) {
                return call_user_func_array([$middlewareInstance, 'handle'], array_merge([$req, $next], $middlewareArgs));
            };
        }

        return call_user_func($next, $request);
    }

    /**
     * @return array{0: string, 1: array<int, string>}|null
     */
    protected function resolveMiddlewareDefinition(mixed $definition): ?array
    {
        if (!is_string($definition) || trim($definition) === '') {
            return null;
        }

        $middlewareClass = trim($definition);
        $middlewareArgs = [];

        if (str_contains($middlewareClass, ':')) {
            [$middlewareClass, $argsStr] = explode(':', $middlewareClass, 2);
            $middlewareArgs = array_values(array_filter(array_map('trim', explode(',', $argsStr)), static fn ($arg) => $arg !== ''));
        }

        $middlewareClass = $this->middlewareAliases[$middlewareClass] ?? $middlewareClass;

        if (!class_exists($middlewareClass)) {
            return null;
        }

        return [$middlewareClass, $middlewareArgs];
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

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();

                if (is_a($className, Request::class, true)) {
                    $args[] = $this->resolveRequestDependency($className, $routeParams, $app);
                    continue;
                }

                $args[] = $app->make($className);
                continue;
            }

            if (array_key_exists($name, $routeParams) && is_scalar($routeParams[$name])) {
                $args[] = $routeParams[$name];
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            $args[] = null;
        }

        return $args;
    }

    /**
     * 解析 Request/FormRequest 参数
     */
    protected function resolveRequestDependency(string $className, array $routeParams, App $app): Request
    {
        $currentRequest = $routeParams['request'] ?? $app->make(Request::class);

        if (!$currentRequest instanceof Request) {
            $currentRequest = $app->make(Request::class);
        }

        if ($className === Request::class) {
            return $currentRequest;
        }

        if (is_subclass_of($className, \Anon\Core\Http\FormRequest::class)) {
            /** @var \Anon\Core\Http\FormRequest $formRequest */
            $formRequest = $app->make($className);
            $formRequest->cloneFrom($currentRequest);
            $formRequest->validateResolved();

            return $formRequest;
        }

        /** @var Request $request */
        $request = $app->make($className);
        return $request;
    }
}
