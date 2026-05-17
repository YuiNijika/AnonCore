<?php

namespace Anon\Core\Console\Commands\Server;

use Anon\Core\Console\Command;

class Run extends Command
{
    protected string $name = 'run';
    protected string $description = 'Start the built-in PHP server in production mode';

    public function execute(array $args): int
    {
        // 获取配置参数，默认 host 127.0.0.1，默认 port 8000
        $host = $this->getOption($args, 'host', '127.0.0.1');
        $port = $this->getOption($args, 'port', '8000');

        // 计算 run 目录路径 
        $docRoot = getcwd() . DIRECTORY_SEPARATOR . 'run';

        if (!is_dir($docRoot)) {
            $this->error("Document root 'run/' not found. Are you running this in the project root?");
            return 1;
        }

        // 注入生产模式环境变量
        putenv('DEBUG_MODE=false');
        putenv('APP_DEBUG=false');
        putenv('APP_ENV=production');

        $this->info("Anon Framework Next server started (PRODUCTION MODE):");
        $this->info("Listening on http://{$host}:{$port}");
        $this->info("Document root is {$docRoot}");
        $this->info("Press Ctrl-C to quit.");

        // 使用 passthru 执行 PHP 内置服务器
        $command = sprintf('php -S %s:%s -t %s', escapeshellarg($host), escapeshellarg($port), escapeshellarg($docRoot));
        
        // 当执行 serve 命令时，这会阻塞直到用户手动停止
        passthru($command, $status);

        return $status;
    }
}
