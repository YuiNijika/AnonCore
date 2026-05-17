<?php

namespace Anon\Core\Storage;

use Exception;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;

class Manager implements Contract
{
    /**
     * @var array 已实例化的存储驱动池
     */
    protected array $disks = [];

    /**
     * @var string 默认存储驱动
     */
    protected string $defaultDisk;

    public function __construct()
    {
        $this->defaultDisk = (string) Config::get('storage.default', Env::get('STORAGE_DISK', 'local'));
    }

    /**
     * 获取指定的存储实例
     */
    public function disk(?string $name = null): Contract
    {
        $name = $name ?: $this->defaultDisk;

        if (!isset($this->disks[$name])) {
            $this->disks[$name] = $this->resolve($name);
        }

        return $this->disks[$name];
    }

    /**
     * 解析并实例化对应的存储驱动
     */
    protected function resolve(string $name): Contract
    {
        switch ($name) {
            case 'local':
                return new Local();
            default:
                throw new Exception("Storage disk [{$name}] is not supported.");
        }
    }

    // --- 代理调用默认驱动的方法 ---

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function get(string $path): string|false
    {
        return $this->disk()->get($path);
    }

    public function put(string $path, string $contents): bool
    {
        return $this->disk()->put($path, $contents);
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($path);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }
}
