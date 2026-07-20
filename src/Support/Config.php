<?php

namespace Anon\Core\Support;

class Config
{
    /**
     * @var array<string, mixed>
     */
    protected array $items = [];

    /**
     * 定义配置项
     *
     * 供 `anon.config.php` 写成 `return Config::define([...])` 时使用：
     * 仅回传数组本身，不写入全局状态，也不做结构校验。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function define(array $config): array
    {
        return $config;
    }

    /**
     * 加载项目根目录下的 anon.config.php
     */
    public function load(string $file): void
    {
        if (!file_exists($file)) {
            return;
        }

        $config = require $file;
        if (!is_array($config)) {
            return;
        }

        $this->items = array_replace_recursive($this->items, $config);
    }

    /**
     * 获取配置项，支持点号语法
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->items;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 设置配置项，支持点号语法
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $items = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                $items[$segment] = [];
            }
            $items = &$items[$segment];
        }

        $items = $value;
    }

    /**
     * 使用给定数组整体替换配置
     *
     * @param array<string, mixed> $items
     */
    public function replace(array $items): void
    {
        $this->items = $items;
    }

    /**
     * 判断配置项是否存在（点号路径，基于 array_key_exists，不依赖哨兵值）
     */
    public function has(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * 获取全部配置
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
