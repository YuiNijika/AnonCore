<?php

namespace Anon\Core\Session;

interface Contract
{
    /**
     * 获取 Session 值
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 设置 Session 值
     */
    public function set(string $key, mixed $value): void;

    /**
     * 判断 Session 是否存在
     */
    public function has(string $key): bool;

    /**
     * 删除指定的 Session
     */
    public function delete(string $key): void;

    /**
     * 清空所有 Session 数据
     */
    public function clear(): void;

    /**
     * 销毁 Session
     */
    public function destroy(): void;

    /**
     * 获取当前 Session ID
     */
    public function getId(): string;

    /**
     * 重新生成 Session ID
     */
    public function regenerateId(bool $deleteOldSession = true): bool;
}