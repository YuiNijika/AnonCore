<?php

namespace Anon\Core\Facade;

use Anon\Core\Database\QueryBuilder;

/**
 * DB Facade类
 * 
 * @method static QueryBuilder table(string $table)
 * @method static array select(string $sql, array $bindings = [])
 * @method static int statement(string $sql, array $bindings = [])
 * @method static string lastInsertId()
 * @method static \PDO getPdo()
 */
class DB extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'db';
    }
}