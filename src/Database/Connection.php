<?php

namespace Anon\Core\Database;

use PDO;
use PDOException;
use Anon\Core\Facade\Env;

class Connection
{
    /**
     * @var PDO|null PDO 连接实例
     */
    protected ?PDO $pdo = null;

    /**
     * @var array 配置信息
     */
    protected array $config = [];
    protected int $transactions = 0;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'type' => Env::get('DATABASE_TYPE', 'mysql'),
            'host' => Env::get('DATABASE_URL', '127.0.0.1'),
            'port' => Env::get('DATABASE_PORT', 3306),
            'database' => Env::get('DATABASE_NAME', 'anon'),
            'username' => Env::get('DATABASE_USER', 'root'),
            'password' => Env::get('DATABASE_PASSWORD', ''),
            'charset' => Env::get('DATABASE_CHARSET', 'utf8mb4'),
            'prefix' => Env::get('DATABASE_PREFIX', ''),
        ], $config);
    }

    /**
     * 获取指定配置或所有配置
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }
        return $this->config[$key] ?? $default;
    }

    /**
     * 获取 PDO 实例
     * @return PDO
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * 手动设置 PDO 实例
     */
    public function setPdo(PDO $pdo): self
    {
        $this->pdo = $pdo;
        return $this;
    }
    protected function connect(): void
    {
        $type = strtolower($this->config['type']);
        $dsn = '';

        switch ($type) {
            case 'mysql':
            case 'pgsql':
                $dsn = sprintf(
                    "%s:host=%s;port=%s;dbname=%s;charset=%s",
                    $type,
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $this->config['charset']
                );
                break;
            case 'sqlite':
                $dsn = sprintf("sqlite:%s", $this->config['database']);
                break;
            case 'sqlsrv':
                $dsn = sprintf(
                    "sqlsrv:Server=%s,%s;Database=%s",
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database']
                );
                break;
            case 'oracle':
            case 'oci':
                $dsn = sprintf(
                    "oci:dbname=//%s:%s/%s;charset=%s",
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $this->config['charset']
                );
                break;
            case 'mongo':
            case 'mongodb':
                throw new \Exception("MongoDB requires a separate driver and is not supported by standard PDO.");
            default:
                throw new \Exception("Unsupported database type: {$type}");
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // 错误抛出异常
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // 默认返回关联数组
            PDO::ATTR_EMULATE_PREPARES   => false,                  // 禁用模拟预处理，使用真实的预处理
            PDO::ATTR_PERSISTENT         => true,                   // 开启持久化连接
        ];

        try {
            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * 开启事务
     */
    public function beginTransaction(): void
    {
        $this->getPdo();
        if ($this->transactions === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactions++;
    }

    /**
     * 提交事务
     */
    public function commit(): void
    {
        $this->getPdo();
        if ($this->transactions === 1) {
            $this->pdo->commit();
        }
        $this->transactions = max(0, $this->transactions - 1);
    }

    /**
     * 回滚事务
     */
    public function rollBack(): void
    {
        $this->getPdo();
        if ($this->transactions === 1) {
            $this->pdo->rollBack();
        }
        $this->transactions = max(0, $this->transactions - 1);
    }

    /**
     * 开始查询构造
     * @param string $table 表名
     * @return QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return (new QueryBuilder($this))->table($table);
    }

    /**
     * 执行原生 SQL 查询并返回结果
     * @param string $sql SQL语句
     * @param array $bindings 绑定参数
     * @return array
     */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    /**
     * 执行原生 SQL 插入/更新/删除操作
     * @param string $sql SQL语句
     * @param array $bindings 绑定参数
     * @return int 受影响的行数
     */
    public function statement(string $sql, array $bindings = []): int
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    /**
     * 获取最后插入的ID
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->getPdo()->lastInsertId();
    }
}