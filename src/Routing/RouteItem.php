<?php

namespace Anon\Core\Routing;

class RouteItem
{
    /**
     * @var string 请求方法
     */
    public string $method;

    /**
     * @var string 路由路径
     */
    public string $uri;

    /**
     * @var string|null 编译后的正则表达式
     */
    public ?string $pattern = null;

    /**
     * @var mixed 路由动作
     */
    public mixed $action;

    /**
     * @var array 路由绑定的中间件
     */
    public array $middlewares = [];

    /**
     * @var string|null 路由名称
     */
    public ?string $name = null;

    /**
     * @var string|null 接口摘要
     */
    public ?string $summary = null;

    /**
     * @var string|null 接口说明
     */
    public ?string $description = null;

    /**
     * @var string[]
     */
    public array $tags = [];

    /**
     * @var array<string, mixed>
     */
    public array $openapi = [];

    /**
     * @var array<string, mixed>
     */
    public array $schema = [];

    public function __construct(string $method, string $uri, mixed $action)
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->action = $action;
    }

    /**
     * 为当前路由绑定中间件
     * @param string|array $middleware 中间件类名或类名数组
     * @return self
     */
    public function middleware(string|array $middleware): self
    {
        $middlewares = is_array($middleware) ? $middleware : [$middleware];
        $this->middlewares = array_merge($this->middlewares, $middlewares);
        return $this;
    }

    /**
     * 设置路由名称
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 设置接口摘要
     */
    public function summary(string $summary): self
    {
        $this->summary = $summary;
        return $this;
    }

    /**
     * 设置接口说明
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * 设置接口标签
     */
    public function tags(string|array $tags): self
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $this->tags = array_values(array_unique(array_filter(array_map('strval', $tags))));
        return $this;
    }

    /**
     * 合并 OpenAPI 扩展声明
     */
    public function openapi(array $openapi): self
    {
        $this->openapi = array_replace_recursive($this->openapi, $openapi);
        return $this;
    }

    /**
     * 声明请求体字段 schema。
     */
    public function schema(array $schema): self
    {
        $this->schema = array_replace_recursive($this->schema, $schema);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'name' => $this->name,
            'summary' => $this->summary,
            'description' => $this->description,
            'tags' => $this->tags,
            'openapi' => $this->openapi,
            'schema' => $this->schema,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function fillMeta(array $meta): self
    {
        $this->name = isset($meta['name']) ? (string) $meta['name'] : null;
        $this->summary = isset($meta['summary']) ? (string) $meta['summary'] : null;
        $this->description = isset($meta['description']) ? (string) $meta['description'] : null;
        $this->tags = is_array($meta['tags'] ?? null) ? array_values(array_map('strval', $meta['tags'])) : [];
        $this->openapi = is_array($meta['openapi'] ?? null) ? $meta['openapi'] : [];
        $this->schema = is_array($meta['schema'] ?? null) ? $meta['schema'] : [];

        return $this;
    }
}
