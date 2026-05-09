<?php

namespace Anon\Core\Storage;

use Anon\Core\Facade\Env;

class Local implements Contract
{
    protected string $root;
    protected string $url;

    public function __construct()
    {
        // 使用框架全局常量
        $this->root = RUNTIME_PATH . '/storage';
        $this->url = APP_URL . '/storage';
        
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    protected function getFullPath(string $path): string
    {
        // 过滤危险字符，防止路径穿越攻击
        $normalizedPath = realpath($this->root . '/' . ltrim($path, '/'));
        
        // 允许新建文件时的路径验证（realpath 对不存在的文件返回 false）
        if ($normalizedPath === false) {
            $normalizedPath = $this->root . '/' . ltrim($path, '/');
            if (str_contains($normalizedPath, '..')) {
                throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed.");
            }
        } elseif (!str_starts_with($normalizedPath, realpath($this->root))) {
            throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed out of root.");
        }
        
        return $normalizedPath;
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
