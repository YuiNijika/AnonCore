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
     * 
     * @param Request $request
     * @return array
     */
    abstract public function toArray(Request $request): array;

    /**
     * 解析资源为数组
     */
    public function resolve(?Request $request = null): array
    {
        $request = $request ?: App::getInstance()->make(Request::class);
        return $this->toArray($request);
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
        return $this->resource->{$key} ?? null;
    }
}
