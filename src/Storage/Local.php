<?php

namespace Anon\Core\Storage;

use Anon\Core\Facade\Env;

class Local implements Contract
{
    protected string $root;
    protected string $url;

    public function __construct()
    {
        // 默认存储在 public/storage 下
        $this->root = defined('BASE_PATH') ? BASE_PATH . '/run/storage' : getcwd() . '/run/storage';
        $this->url = (defined('APP_URL') ? APP_URL : Env::get('APP_URL', 'http://localhost:8000')) . '/storage';
        
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    protected function getFullPath(string $path): string
    {
        // 过滤危险字符，防止路径穿越攻击
        if (str_contains($path, '../') || str_contains($path, '..\\')) {
            throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed.");
        }
        return $this->root . '/' . ltrim($path, '/');
    }

    public function exists(string $path): bool
    {
        return file_exists($this->getFullPath($path));
    }

    public function get(string $path): string|false
    {
        if ($this->exists($path)) {
            return file_get_contents($this->getFullPath($path));
        }
        return false;
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->getFullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $contents) !== false;
    }

    public function delete(string $path): bool
    {
        if ($this->exists($path)) {
            return unlink($this->getFullPath($path));
        }
        return true;
    }

    public function url(string $path): string
    {
        return $this->url . '/' . ltrim($path, '/');
    }
}
