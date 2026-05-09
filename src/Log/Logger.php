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
        $file = $this->getLogFile($type);
        $time = date('Y-m-d H:i:s');
        
        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $content = sprintf("[%s] [%s] %s" . PHP_EOL, $time, strtoupper($level), $message);
        
        // 追加写入文件，并加锁
        file_put_contents($file, $content, FILE_APPEND | LOCK_EX);
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