<?php

namespace Anon\Core\Database\Migration;

use Anon\Core\Facade\DB;

abstract class Seeder
{
    /**
     * 运行数据填充
     */
    abstract public function run(): void;

    /**
     * 调用其他 Seeder
     */
    public function call(string $class): void
    {
        if (class_exists($class)) {
            /** @var Seeder $seeder */
            $seeder = new $class();
            $seeder->run();
        }
    }
}
