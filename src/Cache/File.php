<?php

namespace Anon\Core\Cache;

class File implements Contract
{
    /**
     * @var string 缓存存储目录
     */
    protected string $cachePath;

    public function __construct()
    {
        $this->cachePath = defined('RUNTIME_PATH') ? RUNTIME_PATH . '/cache' : sys_get_temp_dir() . '/anon_cache';
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * 获取缓存文件的绝对路径
     */
    protected function getCacheFile(string $key): string
    {
        return $this->cachePath . '/' . md5($key) . '.cache';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $default;
        }

        $data = unserialize($content);
        if ($data === false) {
            return $default;
        }

        // 检查是否过期 (ttl = 0 表示永不过期)
        if ($data['expire'] !== 0 && time() > $data['expire']) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->getCacheFile($key);
        $expire = ($ttl === null || $ttl <= 0) ? 0 : time() + $ttl;

        $data = [
            'value'  => $value,
            'expire' => $expire
        ];

        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    public function has(string $key): bool
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $data = unserialize($content);
        if ($data === false) {
            return false;
        }

        if ($data['expire'] !== 0 && time() > $data['expire']) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    public function clear(): bool
    {
        $files = glob($this->cachePath . '/*.cache');
        if ($files === false) {
            return false;
        }
        
        $success = true;
        foreach ($files as $file) {
            if (is_file($file)) {
                $success = $success && unlink($file);
            }
        }
        return $success;
    }
}
