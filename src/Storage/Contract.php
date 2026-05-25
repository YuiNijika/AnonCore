<?php

namespace Anon\Core\Storage;

interface Contract
{
    /**
     * 判断文件是否存在
     */
    public function exists(string $path): bool;

    /**
     * 获取文件内容
     */
    public function get(string $path): string|false;

    /**
     * 写入文件内容
     */
    public function put(string $path, string $contents): bool;

    /**
     * 追加文件内容
     */
    public function append(string $path, string $contents): bool;

    /**
     * 复制文件
     */
    public function copy(string $from, string $to): bool;

    /**
     * 移动文件
     */
    public function move(string $from, string $to): bool;

    /**
     * 删除文件
     */
    public function delete(string $path): bool;

    /**
     * 获取文件访问 URL
     */
    public function url(string $path): string;
}
