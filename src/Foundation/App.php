<?php

namespace Anon\Core\Foundation;

use Throwable;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Facade\Route;
use Anon\Core\Facade\Log;
use Anon\Core\Facade\Env;
use Anon\Core\Facade\Hook;
use Anon\Core\Container\Container;

class App extends Container
{
    /**
     * @var string 框架版本号
     */
    public const VERSION = 'v4.0.0-next';

    /**
     * @var string 框架名称
     */
    public const NAME = 'Anon Next';

    /**
     * @var string 应用基础路径
     */
    protected string $basePath;

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

        // 加载环境变量
        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        // 定义常量
        $this->defineConstants();

        // 注册全局异常和错误处理接管
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
     * 注册核心组件到容器的别名
     */
    protected function registerCoreContainerAliases(): void
    {
        $this->bind('router', \Anon\Core\Routing\Router::class);
        $this->bind('db', \Anon\Core\Database\Connection::class);
        $this->bind('log', \Anon\Core\Log\Logger::class);
        $this->bind('env', \Anon\Core\Support\Env::class);
        $this->bind('cache', \Anon\Core\Cache\Manager::class);
        $this->bind('session', \Anon\Core\Session\Manager::class);
        $this->bind('validator', \Anon\Core\Validation\Factory::class);
        $this->bind('event', \Anon\Core\Event\Dispatcher::class);
        $this->bind('auth', \Anon\Core\Auth\AuthManager::class);
        $this->bind('storage', \Anon\Core\Storage\Manager::class);
        $this->bind('http', \Anon\Core\Http\Client::class);
        $this->bind('queue', \Anon\Core\Queue\QueueManager::class);
    }

    /**
     * 定义框架基础常量
     */
    protected function defineConstants(): void
    {
        define('BASE_PATH', $this->basePath);
        define('APP_PATH', $this->basePath . DIRECTORY_SEPARATOR . 'app');
        define('RUNTIME_PATH', $this->basePath . DIRECTORY_SEPARATOR . 'runtime');
        
        // 常用环境变量相关的常量封装
        define('APP_NAME', Env::get('APP_NAME', self::NAME));
        define('APP_ENV', Env::get('APP_ENV', 'production'));
        define('DEBUG_MODE', Env::get('DEBUG_MODE', false));

        // 自动获取 APP_URL
        $defaultUrl = '';
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $defaultUrl = $protocol . $_SERVER['HTTP_HOST'];
        }
        define('APP_URL', Env::get('APP_URL', $defaultUrl));
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
     * 捕获全局异常并以JSON格式响应
     * @param Throwable $e
     */
    public function handleException(Throwable $e): void
    {
        $handler = $this->make(\Anon\Core\Exception\Handler::class);
        $handler->render($e);
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
        $routePath = APP_PATH . DIRECTORY_SEPARATOR . 'route';
        if (is_dir($routePath)) {
            $files = glob($routePath . DIRECTORY_SEPARATOR . '*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    require_once $file;
                }
            }
        }
    }
}
