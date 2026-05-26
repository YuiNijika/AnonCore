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

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * @var array<string, mixed>
     */
    protected array $links = [];

    public function __construct(iterable $resource, string $collects)
    {
        $this->collects = $collects;
        $this->collection = $this->collectResource($resource);
        $this->extractPagination($resource);
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
     * 遍历并转换集合内的数据
     */
    protected function collectResource(iterable $resource): array
    {
        $items = $this->extractItems($resource);
        $collection = [];

        foreach ($items as $item) {
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
        ];
    }

    /**
     * 实现 JsonSerializable 接口
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    protected function extractItems(iterable $resource): iterable
    {
        if (is_array($resource)) {
            foreach (['data', 'items', 'records'] as $key) {
                if (isset($resource[$key]) && is_iterable($resource[$key])) {
                    return $resource[$key];
                }
            }
        }

        if (is_object($resource)) {
            foreach (['items', 'data', 'records'] as $property) {
                if (isset($resource->{$property}) && is_iterable($resource->{$property})) {
                    return $resource->{$property};
                }
            }

            foreach (['items', 'data', 'records'] as $method) {
                if (method_exists($resource, $method)) {
                    $items = $resource->{$method}();
                    if (is_iterable($items)) {
                        return $items;
                    }
                }
            }
        }

        return $resource;
    }

    protected function extractPagination(iterable $resource): void
    {
        $source = is_array($resource) ? $resource : (is_object($resource) ? $resource : null);
        if ($source === null) {
            return;
        }

        $metaMap = [
            'current_page' => ['current_page', 'page', 'currentPage'],
            'per_page' => ['per_page', 'perPage', 'limit'],
            'total' => ['total'],
            'last_page' => ['last_page', 'lastPage'],
            'from' => ['from'],
            'to' => ['to'],
        ];

        foreach ($metaMap as $target => $candidates) {
            $value = $this->readPaginationValue($source, $candidates);
            if ($value !== null) {
                $this->meta[$target] = $value;
            }
        }

        $linkMap = [
            'first' => ['first_page_url', 'firstPageUrl', 'first'],
            'last' => ['last_page_url', 'lastPageUrl', 'last'],
            'prev' => ['prev_page_url', 'previous_page_url', 'prevPageUrl', 'previousPageUrl', 'prev'],
            'next' => ['next_page_url', 'nextPageUrl', 'next'],
        ];

        foreach ($linkMap as $target => $candidates) {
            $value = $this->readPaginationValue($source, $candidates);
            if ($value !== null) {
                $this->links[$target] = $value;
            }
        }
    }

    protected function readPaginationValue(array|object $source, array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if (is_array($source) && array_key_exists($candidate, $source)) {
                return $source[$candidate];
            }

            if (is_object($source)) {
                if (isset($source->{$candidate})) {
                    return $source->{$candidate};
                }

                if (method_exists($source, $candidate)) {
                    return $source->{$candidate}();
                }
            }
        }

        return null;
    }
}
