<?php

namespace Anon\Core\Storage;

use Anon\Core\Facade\Config;

class Local implements Contract
{
    protected string $root;
    protected string $url;

    public function __construct()
    {
        $this->root = (string) Config::get('storage.disks.local.root', RUNTIME_PATH . '/storage');
        $this->url = (string) Config::get('storage.disks.local.url', APP_URL . '/storage');
        
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    protected function getFullPath(string $path): string
    {
        $relativePath = $this->normalizePath($path);
        $realRoot = realpath($this->root);

        if ($realRoot === false) {
            throw new \RuntimeException('Storage root is not available.');
        }

        $target = $realRoot . DIRECTORY_SEPARATOR . $relativePath;
        $parent = dirname($target);

        if (file_exists($target)) {
            $realTarget = realpath($target);
            if ($realTarget === false || !$this->isInsideRoot($realTarget, $realRoot)) {
                throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed out of root.");
            }
            return $realTarget;
        }

        if (is_dir($parent)) {
            $realParent = realpath($parent);
            if ($realParent === false || !$this->isInsideRoot($realParent, $realRoot)) {
                throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed out of root.");
            }
        } else {
            $ancestor = $parent;
            while (!is_dir($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }

            $realAncestor = realpath($ancestor);
            if ($realAncestor === false || !$this->isInsideRoot($realAncestor, $realRoot)) {
                throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed out of root.");
            }
        }

        return $target;
    }

    protected function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('#^[A-Za-z]:/#', $path)) {
            throw new \InvalidArgumentException("Invalid path: Path traversal is not allowed.");
        }

        return $path;
    }

    protected function isInsideRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
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
        return file_put_contents($fullPath, $contents, LOCK_EX) !== false;
    }

    public function append(string $path, string $contents): bool
    {
        $fullPath = $this->getFullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $contents, FILE_APPEND | LOCK_EX) !== false;
    }

    public function copy(string $from, string $to): bool
    {
        $source = $this->getFullPath($from);
        if (!is_file($source)) {
            return false;
        }

        $target = $this->getFullPath($to);
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return copy($source, $target);
    }

    public function move(string $from, string $to): bool
    {
        $source = $this->getFullPath($from);
        if (!is_file($source)) {
            return false;
        }

        $target = $this->getFullPath($to);
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return rename($source, $target);
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
        return $this->url . '/' . str_replace('%2F', '/', rawurlencode($this->normalizePath($path)));
    }
}
