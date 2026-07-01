<?php

namespace Anon\Core\Database\Migration;

use Anon\Core\Facade\DB;
use Anon\Core\Foundation\App;

abstract class Migration
{
    /**
     * 运行迁移
     */
    abstract public function up(): void;

    /**
     * 回滚迁移
     */
    abstract public function down(): void;

    /**
     * 执行原始 SQL 语句
     */
    protected function statement(string $sql): void
    {
        DB::statement($sql);
    }

    protected function schema(): mixed
    {
        return DB::schema();
    }

    protected function connection(): mixed
    {
        return App::getInstance()->make('db');
    }

    protected function mongo(): \Anon\Core\Database\Mongo\Connection
    {
        $connection = $this->connection();

        if (!$connection instanceof \Anon\Core\Database\Mongo\Connection) {
            throw new \RuntimeException('Current database connection is not MongoDB.');
        }

        return $connection;
    }
}
