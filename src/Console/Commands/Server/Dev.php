<?php

namespace Anon\Core\Console\Commands\Server;

use Anon\Core\Console\Command;
use Anon\Core\Support\Config;

class Dev extends Command
{
    protected string $name = 'dev';
    protected string $description = 'Start the built-in PHP development server';

    public function execute(array $args): int
    {
        // 获取配置参数
        $config = new Config();
        $config->load(getcwd() . DIRECTORY_SEPARATOR . 'anon.config.php');
        
        $defaultHost = $config->get('server.host', '127.0.0.1');
        $defaultPort = $config->get('server.port', '8000');

        $host = $this->getOption($args, 'host', $defaultHost);
        $port = $this->getOption($args, 'port', $defaultPort);

        // 计算 run 目录路径 
        $docRoot = getcwd() . DIRECTORY_SEPARATOR . 'run';
        $routerFile = getcwd() . DIRECTORY_SEPARATOR . 'anon';

        if (!is_dir($docRoot)) {
            $this->error("Document root 'run/' not found. Are you running this in the project root?");
            return 1;
        }

        // 注入开发模式环境变量
        putenv('DEBUG_MODE=true');
        putenv('APP_DEBUG=true');
        putenv('APP_ENV=local');

        // 清理一下终端，让输出更整洁
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }

        $this->success("Anon Framework Next development server started");
        $this->info("--------------------------------------------------");
        $this->info(" Local:      http://{$host}:{$port}");
        $this->info(" Mode:       Development");
        $this->info(" Root:       {$docRoot}");
        $this->info("--------------------------------------------------");
        $this->warning("Press Ctrl+C to stop the server.");
        echo PHP_EOL;

        // 使用 passthru 执行 PHP 内置服务器
        // 传递 anon 自身作为路由器脚本以拦截并美化日志
        $command = sprintf(
            'php -S %s:%s -t %s %s', 
            escapeshellarg($host), 
            escapeshellarg($port), 
            escapeshellarg($docRoot),
            escapeshellarg($routerFile)
        );
        
        // 当执行 serve 命令时，这会阻塞直到用户手动停止
        passthru($command, $status);

        return $status;
    }
}
