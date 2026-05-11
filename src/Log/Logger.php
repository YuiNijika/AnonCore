<?php

namespace Anon\Core\Log;

class Logger
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

    public function __construct(string $logPath = '')
    {
        $this->logPath = $logPath ?: (defined('RUNTIME_PATH') ? RUNTIME_PATH . '/log' : __DIR__ . '/../../runtime/log');
        $this->ensureDirectoryExists($this->logPath);
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
        if (!$this->flushRegistered) {
            register_shutdown_function([$this, 'flush']);
            $this->flushRegistered = true;
        }

        $time = date('Y-m-d H:i:s.v'); // 增加毫秒级时间戳
        
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
     * 将所有内存日志强制落盘（在应用 Shutdown 时自动调用）
     */
    public function flush(): void
    {
        foreach (array_keys($this->logBuffer) as $type) {
            $this->flushType($type);
        }
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
        $this->log('debug', $message, $type);
    }

    /**
     * 记录 WARNING 级别日志
     */
    public function warning(string|array $message, string $type = 'app'): void
    {
        $this->log('warning', $message, $type);
    }
}