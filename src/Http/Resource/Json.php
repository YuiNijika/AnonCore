<?php

namespace Anon\Core\Http\Resource;

use JsonSerializable;
use Anon\Core\Http\Request;
use Anon\Core\Foundation\App;

abstract class Json implements JsonSerializable
{
    /**
     * @var mixed 基础资源数据
     */
    public mixed $resource;

    /**
     * 包装键名，默认不包装
     */
    public static string $wrap = 'data';

    /**
     * @var array<string, mixed>
     */
    protected array $additional = [];

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * @var array<string, mixed>
     */
    protected array $links = [];

    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * 创建一个新的资源实例
     */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * 创建一个资源集合
     */
    public static function collection(mixed $resource): Collection
    {
        return new Collection($resource, static::class);
    }

    /**
     * 将资源转换为数组
     */
    abstract public function toArray(Request $request): array;

    /**
     * 追加响应顶层数据
     */
    public function additional(array $data): static
    {
        $this->additional = array_replace_recursive($this->additional, $data);
        return $this;
    }

    /**
     * 追加 meta 数据
     */
    public function meta(array $meta): static
    {
        $this->meta = array_replace_recursive($this->meta, $meta);
        return $this;
    }

    /**
     * 追加 links 数据
     */
    public function links(array $links): static
    {
        $this->links = array_replace_recursive($this->links, $links);
        return $this;
    }

    /**
     * 解析资源为数组
     */
    public function resolve(?Request $request = null): array
    {
        $request = $request ?: App::getInstance()->make(Request::class);
        return $this->toArray($request);
    }

    /**
     * 解析为统一响应可识别的 payload
     *
     * @return array{data: mixed, meta: array, links: array}
     */
    public function toResponsePayload(?Request $request = null): array
    {
        return [
            'data' => $this->resolve($request),
            'meta' => $this->meta,
            'links' => $this->links,
        ] + $this->additional;
    }

    /**
     * 实现 JsonSerializable 接口
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * 魔术方法，代理属性访问到基础资源
     */
    public function __get(string $key)
    {
        if (is_array($this->resource)) {
            return $this->resource[$key] ?? null;
        }

        return $this->resource->{$key} ?? null;
    }
}
