<?php

namespace Anon\Core\Console;

class Application
{
    /**
     * @var string 框架版本号
     */
    protected string $version;

    /**
     * @var Command[] 注册的命令集合
     */
    protected array $commands = [];

    public function __construct(string $version = undefined)
    {
        $this->version = $version;
        $this->registerDefaultCommands();
    }

    /**
     * 注册内置的默认命令
     */
    protected function registerDefaultCommands(): void
    {
        $this->add(new Commands\Server\Dev());
        $this->add(new Commands\Server\Run());
        $this->add(new Commands\Config\Cache());
        $this->add(new Commands\Config\Clear());
        $this->add(new Commands\Route\Cache());
        $this->add(new Commands\Route\Clear());
        $this->add(new Commands\Route\RouteList());
        $this->add(new Commands\Make\Controller());
        $this->add(new Commands\Make\Model());
        $this->add(new Commands\Make\Request());
        $this->add(new Commands\Make\Resource());
        $this->add(new Commands\Make\Middleware());
        $this->add(new Commands\Make\Migration());
        $this->add(new Commands\Db\Migrate());
        $this->add(new Commands\Make\Seeder());
        $this->add(new Commands\Db\Seed());
        $this->add(new Commands\Make\Job());
        $this->add(new Commands\Queue\Work());
        $this->add(new Commands\Queue\Failed());
        $this->add(new Commands\Queue\Retry());
        $this->add(new Commands\Queue\ClearFailed());
    }

    /**
     * 添加一个命令到应用中
     */
    public function add(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * 运行控制台应用
     */
    public function run(array $argv): int
    {
        // $argv[0] 通常是脚本名称
        array_shift($argv);

        // 如果没有提供任何参数，显示帮助信息
        if (empty($argv)) {
            $this->showHelp();
            return 0;
        }

        $commandName = array_shift($argv);

        // 处理内置的 help 或 version 请求
        if ($commandName === '--help' || $commandName === '-h' || $commandName === 'help') {
            $this->showHelp();
            return 0;
        }
        if ($commandName === '--version' || $commandName === '-v' || $commandName === 'version') {
            $version = class_exists(\Anon\Core\Facade\App::class) ? \Anon\Core\Facade\App::version() : $this->version;
            echo "Anon Framework Next \033[32m{$version}\033[0m\n";
            return 0;
        }

        // 查找并执行对应的命令
        if (isset($this->commands[$commandName])) {
            try {
                return $this->commands[$commandName]->execute($argv);
            } catch (\Exception $e) {
                echo "\033[31m[ERROR]\033[0m " . $e->getMessage() . PHP_EOL;
                return 1;
            }
        }

        echo "\033[31m[ERROR]\033[0m Command '{$commandName}' is not defined.\n";
        return 1;
    }

    /**
     * 显示帮助信息和命令列表
     */
    protected function showHelp(): void
    {
        echo "Anon Framework Next Console Tool \033[32m{$this->version}\033[0m\n\n";
        echo "\033[33mUsage:\033[0m\n";
        echo "  command [options] [arguments]\n\n";
        
        echo "\033[33mAvailable commands:\033[0m\n";
        
        // 按字母顺序排序输出
        ksort($this->commands);
        
        foreach ($this->commands as $name => $command) {
            $nameStr = str_pad($name, 20);
            echo "  \033[32m{$nameStr}\033[0m {$command->getDescription()}\n";
        }
        echo "\n";
    }
}
