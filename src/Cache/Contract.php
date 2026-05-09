<?php

namespace Anon\Core\Cache;

interface Contract
{
    /**
     * 获取缓存
     * @param string $key 缓存键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 设置缓存
     * @param string $key 缓存键名
     * @param mixed $value 缓存值
     * @param int|null $ttl 过期时间(秒)，null 为永不过期
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * 判断缓存是否存在
     * @param string $key 缓存键名
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * 删除缓存
     * @param string $key 缓存键名
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * 清空所有缓存
     * @return bool
     */
    public function clear(): bool;

    /**
     * 递增缓存值
     * @param string $key 缓存键名
     * @param int $value 递增的步长
     * @return int|bool 递增后的值，失败返回 false
     */
    public function increment(string $key, int $value = 1): int|bool;
}