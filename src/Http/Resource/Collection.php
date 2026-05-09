<?php

namespace Anon\Core\Http\Resource;

use JsonSerializable;
use Anon\Core\Http\Request;
use Anon\Core\Foundation\App;

class Collection implements JsonSerializable
{
    /**
     * @var iterable 资源集合
     */
    public iterable $collection;

    /**
     * @var string 用于格式化集合内元素的 Resource 类名
     */
    public string $collects;

    public function __construct(iterable $resource, string $collects)
    {
        $this->collects = $collects;
        $this->collection = $this->collectResource($resource);
    }

    /**
     * 遍历并转换集合内的数据
     */
    protected function collectResource(iterable $resource): array
    {
        $collection = [];
        foreach ($resource as $item) {
            $collection[] = new $this->collects($item);
        }
        return $collection;
    }

    /**
     * 解析资源集合为数组
     */
    public function resolve(?Request $request = null): array
    {
        $request = $request ?: App::getInstance()->make(Request::class);
        
        $data = [];
        foreach ($this->collection as $item) {
            $data[] = $item->resolve($request);
        }

        return $data;
    }

    /**
     * 实现 JsonSerializable 接口
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }
}
