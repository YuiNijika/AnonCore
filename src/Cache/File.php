<?php

namespace Anon\Core\Cache;

use Anon\Core\Facade\Config;

class File implements Contract
{
    /**
     * @var string 缓存存储目录
     */
    protected string $cachePath;

    public function __construct()
    {
        $this->cachePath = (string) Config::get('cache.path', RUNTIME_PATH . '/cache');
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

        // 检查是否过期
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
        $success = true;
        
        try {
            $iterator = new \DirectoryIterator($this->cachePath);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'cache') {
                    $success = $success && unlink($fileInfo->getPathname());
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return $success;
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        $file = $this->getCacheFile($key);
        // 使用文件锁避免并发问题
        $fp = fopen($file, 'c+');
        if (!$fp) {
            return false;
        }

        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            $data = $content ? unserialize($content) : false;
            
            $currentValue = 0;
            $expire = 0;

            if (is_array($data)) {
                if ($data['expire'] === 0 || time() <= $data['expire']) {
                    $currentValue = (int)$data['value'];
                    $expire = $data['expire'];
                }
            }

            $newValue = $currentValue + $value;
            $newData = [
                'value' => $newValue,
                'expire' => $expire
            ];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, serialize($newData));
            flock($fp, LOCK_UN);
            fclose($fp);

            return $newValue;
        }

        fclose($fp);
        return false;
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        
        if ($value !== $default) {
            $this->delete($key);
        }
        
        return $value;
    }
}
