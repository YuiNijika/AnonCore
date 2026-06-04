<?php

namespace Anon\Core\Database\Migration;

use Anon\Core\Facade\DB;
use PDO;

class Migrator
{
    protected string $table = 'migrations';
    protected string $path;

    public function __construct()
    {
        $this->path = APP_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    /**
     * 确保存储迁移记录的表存在
     */
    protected function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INT NOT NULL
        )";
        // SQLite 和其他数据库的兼容：
        // 这里使用尽量通用的语法，如果不支持 AUTO_INCREMENT 可能会报错
        // 简单处理：判断数据库驱动
        $driver = DB::getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL
            )";
        }

        DB::statement($sql);
    }

    /**
     * 获取所有已执行的迁移
     */
    protected function getRanMigrations(): array
    {
        $this->ensureTableExists();
        $rows = DB::select("SELECT migration FROM {$this->table} ORDER BY batch ASC, migration ASC");
        return array_column($rows, 'migration');
    }

    /**
     * 获取下一个批次号
     */
    protected function getNextBatchNumber(): int
    {
        $this->ensureTableExists();
        $rows = DB::select("SELECT MAX(batch) as batch FROM {$this->table}");
        return (int)($rows[0]['batch'] ?? 0) + 1;
    }

    /**
     * 执行所有未运行的迁移
     */
    public function run(): array
    {
        $ran = $this->getRanMigrations();
        $files = glob($this->path . DIRECTORY_SEPARATOR . '*.php');
        if (!$files) {
            return [];
        }

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $ran)) {
                $pending[] = $file;
            }
        }

        if (empty($pending)) {
            return [];
        }

        $batch = $this->getNextBatchNumber();
        $executed = [];

        foreach ($pending as $file) {
            require_once $file;
            $name = basename($file, '.php');
            // 约定类名为去除了时间前缀的文件名，例如 20260510_123456_CreateUsersTable -> CreateUsersTable
            $parts = explode('_', $name);
            $className = $parts[count($parts) - 1];

            if (class_exists($className)) {
                /** @var Migration $migration */
                $migration = new $className();
                $migration->up();

                // 记录到数据库
                DB::statement("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)", [$name, $batch]);
                $executed[] = $name;
            }
        }

        return $executed;
    }
}
