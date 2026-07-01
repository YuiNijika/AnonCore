<?php

namespace Anon\Core\Facade;

use Anon\Core\Database\QueryBuilder;

/**
 * DB Facade类
 * 
 * @method static QueryBuilder table(string $table)
 * @method static mixed schema()
 * @method static array select(string $sql, array $bindings = [])
 * @method static int statement(string $sql, array $bindings = [])
 * @method static ?string statementReturningValue(string $sql, array $bindings = [], string $outputParameter = ':anon_returning_value', int $length = 4000)
 * @method static string lastInsertId(?string $sequence = null)
 * @method static ?string tryLastInsertId(?string $sequence = null)
 * @method static \PDO getPdo()
 * @method static void beginTransaction()
 * @method static void commit()
 * @method static void rollBack()
 * @method static mixed transaction(callable $callback)
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
