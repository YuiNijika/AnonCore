<?php

namespace Anon\Core\Session;

use SessionHandlerInterface;
use Anon\Core\Facade\Config;

class File implements SessionHandlerInterface
{
    /**
     * @var string Session 文件存储目录
     */
    protected string $path;

    public function __construct()
    {
        $defaultPath = defined('RUNTIME_PATH') ? RUNTIME_PATH . '/session' : sys_get_temp_dir() . '/anon_session';
        $this->path = (string) Config::get('session.path_storage', $defaultPath);
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $file = $this->path . '/sess_' . $id;
        
        if (file_exists($file)) {
            // 校验文件修改时间是否过期
            if (filemtime($file) >= time()) {
                return (string) file_get_contents($file);
            }
            // 过期则主动删除
            unlink($file);
        }
        
        return '';
    }

    public function write(string $id, string $data): bool
    {
        $file = $this->path . '/sess_' . $id;
        
        $result = file_put_contents($file, $data, LOCK_EX) !== false;
        
        if ($result) {
            // 更新修改时间为过期时间标识
            $lifetime = (int) ini_get('session.gc_maxlifetime');
            touch($file, time() + $lifetime);
        }
        
        return $result;
    }

    public function destroy(string $id): bool
    {
        $file = $this->path . '/sess_' . $id;
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $files = glob($this->path . '/sess_*');
        if ($files === false) {
            return false;
        }

        $now = time();
        $deleted = 0;

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $now) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
