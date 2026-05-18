<?php

namespace Anon\Core\Console;

use Anon\Core\Foundation\App;

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

    /**
     * 在控制台输出表格
     *
     * @param array $headers 表头数组
     * @param array $rows 数据行数组 (二维数组)
     */
    protected function table(array $headers, array $rows): void
    {
        // 计算每列最大宽度
        $columnWidths = [];
        foreach ($headers as $index => $header) {
            $columnWidths[$index] = mb_strwidth((string) $header);
        }
        
        foreach ($rows as $row) {
            $rowValues = array_values($row);
            foreach ($rowValues as $index => $value) {
                $width = mb_strwidth((string) $value);
                if (!isset($columnWidths[$index]) || $width > $columnWidths[$index]) {
                    $columnWidths[$index] = $width;
                }
            }
        }

        // 生成分隔线
        $separator = '+';
        foreach ($columnWidths as $width) {
            $separator .= str_repeat('-', $width + 2) . '+';
        }

        // 输出表头
        echo $separator . PHP_EOL;
        echo '|';
        foreach ($headers as $index => $header) {
            $padding = $columnWidths[$index] - mb_strwidth((string) $header);
            echo ' ' . $header . str_repeat(' ', $padding) . ' |';
        }
        echo PHP_EOL;
        echo $separator . PHP_EOL;

        // 输出数据行
        foreach ($rows as $row) {
            echo '|';
            $rowValues = array_values($row);
            foreach ($rowValues as $index => $value) {
                // 处理缺失列
                $valStr = (string) $value;
                $width = isset($columnWidths[$index]) ? $columnWidths[$index] : 0;
                $padding = max(0, $width - mb_strwidth($valStr));
                echo ' ' . $valStr . str_repeat(' ', $padding) . ' |';
            }
            echo PHP_EOL;
        }
        
        if (!empty($rows)) {
            echo $separator . PHP_EOL;
        }
    }

    /**
     * 获取项目根目录
     */
    protected function projectRoot(): string
    {
        return getcwd() ?: '.';
    }

    /**
     * 获取 runtime 目录
     */
    protected function runtimePath(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime';
    }

    /**
     * 获取缓存目录
     */
    protected function cachePath(): string
    {
        return $this->runtimePath() . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * 确保目录存在
     */
    protected function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * 在控制台环境中引导应用，便于复用配置、路由和容器能力
     *
     * @param array<string, scalar|null> $environment
     */
    protected function bootstrapApp(array $environment = []): App
    {
        foreach ($environment as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            $normalized = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            putenv($key . '=' . $normalized);
            $_ENV[$key] = $normalized;
            $_SERVER[$key] = $normalized;
        }

        return new App($this->projectRoot());
    }
}
