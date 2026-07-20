<?php

namespace Anon\Core\Log;

use Anon\Core\Facade\Config;

class Manager
{
    /**
     * @var string 日志基础目录
     */
    protected string $logPath;

    /**
     * @var string 当前日期格式，用于日志按天分割
     */
    protected string $dateFormat = 'Y-m-d';

    /**
     * @var array 内存中的日志缓冲池
     */
    protected array $logBuffer = [];

    /**
     * @var bool 是否已经注册了自动刷新日志的钩子
     */
    protected bool $flushRegistered = false;

    /**
     * @var bool 是否启用 debug 模式
     */
    protected bool $isDebug;

    /**
     * @var string 最低写入级别：debug|info|warning|error
     */
    protected string $minLevel = 'debug';

    /**
     * 按天目录保留天数；0 表示不自动清理
     */
    protected int $maxFiles = 14;

    /**
     * @var array<string, int>
     */
    protected array $levelWeights = [
        'debug' => 10,
        'info' => 20,
        'warning' => 30,
        'error' => 40,
    ];

    public function __construct(string $logPath = '')
    {
        $defaultPath = defined('RUNTIME_PATH') ? RUNTIME_PATH . '/log' : __DIR__ . '/../../runtime/log';
        $this->logPath = $logPath ?: (string) Config::get('log.path', $defaultPath);
        $this->ensureDirectoryExists($this->logPath);
        $this->isDebug = defined('DEBUG_MODE')
            ? (bool) DEBUG_MODE
            : (bool) Config::get('app.debug', ($_ENV['DEBUG_MODE'] ?? $_ENV['APP_DEBUG'] ?? false));

        // 非 debug 默认 info，避免生产环境刷满 debug/info 噪声；可用 log.level 覆盖
        $configuredLevel = strtolower((string) Config::get('log.level', $this->isDebug ? 'debug' : 'info'));
        $this->minLevel = array_key_exists($configuredLevel, $this->levelWeights) ? $configuredLevel : 'info';
        $this->maxFiles = max(0, (int) Config::get('log.max_files', 14));
    }

    /**
     * 确保日志目录存在
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * 获取当前日志文件路径
     */
    protected function getLogFile(string $type = 'app'): string
    {
        $date = date($this->dateFormat);
        $dir = $this->logPath . '/' . $date;
        $this->ensureDirectoryExists($dir);
        
        return $dir . '/' . $type . '.log';
    }

    /**
     * 写入日志
     * @param string $level 日志级别
     * @param string|array $message 日志内容
     * @param string $type 日志分类
     */
    public function log(string $level, string|array $message, string $type = 'app'): void
    {
        $level = strtolower($level);
        if (!$this->shouldWrite($level)) {
            return;
        }

        if (!$this->flushRegistered) {
            register_shutdown_function([$this, 'flush']);
            $this->flushRegistered = true;
        }

        $time = date('Y-m-d H:i:s.v');

        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $content = sprintf("[%s] [%s] %s" . PHP_EOL, $time, strtoupper($level), $message);

        $this->logBuffer[$type][] = $content;

        // 当单个请求产生大量日志时（例如超过 1000 条），触发自动落盘防止内存泄漏
        if (count($this->logBuffer[$type]) >= 1000) {
            $this->flushType($type);
        }
    }

    protected function shouldWrite(string $level): bool
    {
        $current = $this->levelWeights[$level] ?? $this->levelWeights['info'];
        $minimum = $this->levelWeights[$this->minLevel] ?? $this->levelWeights['info'];

        return $current >= $minimum;
    }

    /**
     * 将指定分类的内存日志强制落盘
     */
    protected function flushType(string $type): void
    {
        if (empty($this->logBuffer[$type])) {
            return;
        }

        $file = $this->getLogFile($type);
        $content = implode('', $this->logBuffer[$type]);
        
        file_put_contents($file, $content, FILE_APPEND | LOCK_EX);
        
        $this->logBuffer[$type] = [];
    }

    /**
     * 将所有内存日志强制落盘
     */
    public function flush(): void
    {
        foreach (array_keys($this->logBuffer) as $type) {
            $this->flushType($type);
        }

        $this->pruneOldLogs();
    }

    /**
     * 清理超出 log.max_files 保留天数的按日日志目录
     */
    protected function pruneOldLogs(): void
    {
        if ($this->maxFiles <= 0 || !is_dir($this->logPath)) {
            return;
        }

        $threshold = strtotime('-' . $this->maxFiles . ' days');
        if ($threshold === false) {
            return;
        }

        try {
            $iterator = new \DirectoryIterator($this->logPath);
        } catch (\Throwable) {
            return;
        }

        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $name = $entry->getFilename();
            // 仅处理 Y-m-d 命名目录
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                continue;
            }

            $dirTime = strtotime($name . ' 00:00:00');
            if ($dirTime === false || $dirTime >= $threshold) {
                continue;
            }

            $this->removeDirectory($entry->getPathname());
        }
    }

    protected function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }

    /**
     * 记录 INFO 级别日志
     */
    public function info(string|array $message, string $type = 'app'): void
    {
        $this->log('info', $message, $type);
    }

    /**
     * 记录 ERROR 级别日志
     */
    public function error(string|array $message, string $type = 'app'): void
    {
        $this->log('error', $message, $type);
    }

    /**
     * 记录 DEBUG 级别日志
     */
    public function debug(string|array $message, string $type = 'app'): void
    {
        // debug 方法语义上仍受 isDebug 约束；级别过滤由 log()/shouldWrite 统一处理
        if (!$this->isDebug) {
            return;
        }

        $this->log('debug', $message, $type);
    }

    /**
     * 设置最低写入级别
     */
    public function setMinLevel(string $level): self
    {
        $level = strtolower($level);
        if (array_key_exists($level, $this->levelWeights)) {
            $this->minLevel = $level;
        }

        return $this;
    }

    /**
     * 记录 WARNING 级别日志
     */
    public function warning(string|array $message, string $type = 'app'): void
    {
        $this->log('warning', $message, $type);
    }

    /**
     * 设置是否启用 debug 模式
     */
    public function setDebug(bool $isDebug): self
    {
        $this->isDebug = $isDebug;
        return $this;
    }

    /**
     * 获取当前是否启用 debug 模式
     */
    public function isDebug(): bool
    {
        return $this->isDebug;
    }
}
