<?php

namespace Anon\Core\Foundation;

use Throwable;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Facade\Route;
use Anon\Core\Facade\Log;
use Anon\Core\Facade\Env;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Hook;
use Anon\Core\Container\Container;

class App extends Container
{
    /**
     * @var string 框架版本
     */
    public const VERSION = 'v4.0.0-next-alpha2';

    /**
     * @var string 框架名称
     */
    public const NAME = 'Anon Framework Next';

    /**
     * @var string OpenAPI 版本
     */
    public const OPENAPI_VERSION = 'v1.0.0';

    /**
     * @var string 应用基础路径
     */
    protected string $basePath;

    /**
     * @var ServiceProvider[]
     */
    protected array $providers = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        
        // 将自己绑定为容器单例
        static::setInstance($this);
        $this->instance('app', $this);
        $this->instance(Container::class, $this);

        $this->bootstrap();
    }

    /**
     * 运行应用程序
     * @param string $basePath 应用基础路径
     */
    public static function run(string $basePath): void
    {
        $app = new self($basePath);
        
        Hook::trigger('app_init', $app);

        $request = Request::capture();
        $app->instance(Request::class, $request);
        $app->instance('request', $request);
        
        Hook::trigger('request_begin', $request);

        $response = $app->handle($request);
        
        Hook::trigger('response_send', $response);
        $response->send();

        Hook::trigger('app_end', $app);
    }

    /**
     * 引导应用程序启动
     */
    protected function bootstrap(): void
    {
        // 注册核心组件到容器
        $this->registerCoreContainerAliases();

        // 按 Vite 风格加载环境变量
        $this->loadEnvironmentFiles();

        // 加载结构化配置
        $this->loadConfiguration();

        // 定义常量
        $this->defineConstants();

        // 注册并启动服务提供者
        $this->registerConfiguredProviders();
        $this->bootProviders();

        // 注册全局异常和错误处理接口
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);

        // 加载默认系统钩子
        $hookFile = APP_PATH . DIRECTORY_SEPARATOR . 'hook.php';
        if (file_exists($hookFile)) {
            require $hookFile;
        }

        // 加载全局路由
        $this->loadRoutes();
        Hook::trigger('route_loaded');
    }

    /**
     * 加载环境变量文件
     *
     * 支持：
     * - .env
     * - .env.local
     * - .env.{APP_ENV}
     * - .env.{APP_ENV}.local
     */
    protected function loadEnvironmentFiles(): void
    {
        $envPath = $this->basePath . DIRECTORY_SEPARATOR;

        Env::load($envPath . '.env');
        Env::load($envPath . '.env.local');

        $mode = (string) Env::get('APP_ENV', 'production');
        if ($mode !== '') {
            Env::load($envPath . '.env.' . $mode);
            Env::load($envPath . '.env.' . $mode . '.local');
        }
    }

    /**
     * 加载配置，优先使用配置缓存
     */
    protected function loadConfiguration(): void
    {
        $configCache = $this->getCacheFile('config.php');

        if (!$this->cacheDisabled('ANON_DISABLE_CONFIG_CACHE') && file_exists($configCache)) {
            $config = require $configCache;
            if (is_array($config)) {
                Config::replace($config);
                return;
            }
        }

        Config::load($this->basePath . DIRECTORY_SEPARATOR . 'anon.config.php');
    }

    /**
     * 注册核心组件到容器的别名
     */
    protected function registerCoreContainerAliases(): void
    {
        $this->bind('router', \Anon\Core\Routing\Router::class);
        $this->bind('db', \Anon\Core\Database\Connection::class);
        $this->bind('log', \Anon\Core\Log\Manager::class);
        $this->bind('env', \Anon\Core\Support\Env::class);
        $this->bind('config', \Anon\Core\Support\Config::class);
        $this->bind('cache', \Anon\Core\Cache\Manager::class);
        $this->bind('session', \Anon\Core\Session\Manager::class);
        $this->bind('validator', \Anon\Core\Validation\Factory::class);
        $this->bind('event', \Anon\Core\Event\Dispatcher::class);
        $this->bind('auth', \Anon\Core\Auth\Manager::class);
        $this->bind('storage', \Anon\Core\Storage\Manager::class);
        $this->bind('http', \Anon\Core\Http\Client::class);
        $this->bind('queue', \Anon\Core\Queue\Manager::class);

        $actionRegistry = new \Anon\Core\Action\Registry();
        $this->instance('action.registry', $actionRegistry);
        $this->instance(\Anon\Core\Action\Registry::class, $actionRegistry);
        $this->bind('action.dispatcher', function ($app) {
            return new \Anon\Core\Action\Dispatcher($app->make('action.registry'));
        });
    }

    /**
     * 注册配置中的服务提供者
     */
    protected function registerConfiguredProviders(): void
    {
        $providers = Config::get('app.providers', []);
        if (!is_array($providers)) {
            return;
        }

        foreach ($providers as $providerClass) {
            if (!is_string($providerClass) || !class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass($this);
            if (!$provider instanceof ServiceProvider) {
                continue;
            }

            $provider->register();
            $this->providers[] = $provider;
        }
    }

    /**
     * 启动已注册的服务提供者
     */
    protected function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    /**
     * 定义框架基础常量
     */
    protected function defineConstants(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $this->basePath);
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', $this->basePath . DIRECTORY_SEPARATOR . 'app');
        }
        if (!defined('RUNTIME_PATH')) {
            define('RUNTIME_PATH', $this->basePath . DIRECTORY_SEPARATOR . 'runtime');
        }
        
        // 常用环境变量相关的常量封装
        $appName = Config::get('app.name', Env::get('APP_NAME', self::NAME));
        $appEnv = Config::get('app.env', Env::get('APP_ENV', 'production'));
        $debugMode = Config::get('app.debug', Env::get('DEBUG_MODE', Env::get('APP_DEBUG', false)));

        if (!defined('APP_NAME')) {
            define('APP_NAME', $appName);
        }
        if (!defined('APP_ENV')) {
            define('APP_ENV', $appEnv);
        }
        if (!defined('DEBUG_MODE')) {
            define('DEBUG_MODE', $debugMode);
        }
        if (!defined('APP_DEBUG')) {
            define('APP_DEBUG', $debugMode);
        }

        // 自动获取 APP_URL
        $defaultUrl = '';
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $defaultUrl = $protocol . $_SERVER['HTTP_HOST'];
        }
        if (!defined('APP_URL')) {
            define('APP_URL', Config::get('app.url', Env::get('APP_URL', $defaultUrl)));
        }
    }

    /**
     * 获取应用基础路径
     * @return string 应用基础路径
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * 获取框架基础信息
     * @return array
     */
    public function getInfo(): array
    {
        return [
            'name' => self::NAME,
            'version' => self::VERSION,
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'base_path' => $this->basePath,
        ];
    }

    /**
     * 处理HTTP请求
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        // 捕获请求并分发路由
        $info = $this->getInfo();
        return Route::dispatch($request);
    }

    /**
     * 处理异常并返回响应
     */
    public function handleException(\Throwable $e): void
    {
        // 记录错误日志，将上下文合并到消息中
        $errorMsg = $e->getMessage() . "\n" . json_encode([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        Log::error($errorMsg, 'exception');

        $code = 500;
        $message = $e->getMessage();
        $data = null;

        // 如果是 HTTP 异常，提取状态码和数据
        if ($e instanceof \Anon\Core\Exception\Http) {
            $code = $e->getStatusCode();
            $data = $e->getData();
        } else {
            // 在非调试模式下，隐藏真实错误信息
            if (!Config::get('app.debug', Env::get('DEBUG_MODE', Env::get('APP_DEBUG', false)))) {
                $message = 'Internal Server Error';
            } else {
                $data = [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace(),
                ];
            }
        }

        // 返回 JSON 格式错误
        $response = Response::json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $code);

        $response->send();
    }

    /**
     * 将PHP错误转换为ErrorException抛出由handleException统一处理
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return bool
     * @throws \ErrorException
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * 加载全局路由文件
     */
    protected function loadRoutes(): void
    {
        $routeCache = $this->getCacheFile('routes.php');
        if (!$this->cacheDisabled('ANON_DISABLE_ROUTE_CACHE') && file_exists($routeCache)) {
            $payload = require $routeCache;
            if (is_array($payload)) {
                /** @var \Anon\Core\Routing\Router $router */
                $router = $this->make('router');
                $router->loadCachedRoutes($payload);

                /** @var \Anon\Core\Action\Registry $actions */
                $actions = $this->make('action.registry');
                if (is_array($payload['actions'] ?? null)) {
                    $actions->loadCached($payload['actions']);
                }

                $this->mountActionEndpoint();
                return;
            }
        }

        $routePath = APP_PATH . DIRECTORY_SEPARATOR . 'route';
        if (is_dir($routePath)) {
            $files = glob($routePath . DIRECTORY_SEPARATOR . '*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    require_once $file;
                }
            }
        }

        $this->mountActionEndpoint();
    }

    /**
     * 挂载 Server Actions 统一入口
     */
    protected function mountActionEndpoint(): void
    {
        $path = '/' . trim((string) Config::get('actions.path', '/_actions'), '/');
        if ($path === '/') {
            $path = '/_actions';
        }

        /** @var \Anon\Core\Routing\Router $router */
        $router = $this->make('router');
        $router->post($path . '/{action}', [\Anon\Core\Action\Dispatcher::class, 'handle'])
            ->name('server_actions.dispatch')
            ->summary('Call a registered server action')
            ->tags(['Server Actions']);
    }

    /**
     * 获取缓存文件路径
     */
    protected function getCacheFile(string $fileName): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * 判断是否禁用某类缓存
     */
    protected function cacheDisabled(string $key): bool
    {
        return (bool) Env::get($key, false);
    }
}
