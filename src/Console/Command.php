<?php

namespace Anon\Core\Console;

abstract class Command
{
    /**
     * @var string 命令名称
     */
    protected string $name = '';

    /**
     * @var string 命令描述
     */
    protected string $description = '';

    /**
     * 获取命令名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取命令描述
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 执行命令的具体逻辑
     * @param array $args 命令行参数
     * @return int 退出码
     */
    abstract public function execute(array $args): int;

    /**
     * 在控制台输出普通文本
     */
    protected function info(string $message): void
    {
        echo "\033[36m[INFO]\033[0m {$message}" . PHP_EOL;
    }

    /**
     * 在控制台输出成功文本
     */
    protected function success(string $message): void
    {
        echo "\033[32m[SUCCESS]\033[0m {$message}" . PHP_EOL;
    }

    /**
     * 在控制台输出错误文本
     */
    protected function error(string $message): void
    {
        echo "\033[31m[ERROR]\033[0m {$message}" . PHP_EOL;
    }

    /**
     * 在控制台输出警告文本
     */
    protected function warning(string $message): void
    {
        echo "\033[33m[WARNING]\033[0m {$message}" . PHP_EOL;
    }

    /**
     * 获取指定名称的命令行参数或选项
     */
    protected function getOption(array $args, string $name, mixed $default = null): mixed
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen($name) + 3);
            }
        }
        return $default;
    }
}