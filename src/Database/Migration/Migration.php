<?php

namespace Anon\Core\Database\Migration;

use Anon\Core\Facade\DB;

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
        DB::execute($sql);
    }
}
