<?php

namespace Anon\Core\Action;

class Definition
{
    /**
     * @var string[]
     */
    protected array $middlewares = [];

    protected ?string $summary = null;

    protected ?string $description = null;

    /**
     * @var string[]
     */
    protected array $tags = [];

    /**
     * @var array<string, mixed>
     */
    protected array $openapi = [];

    /**
     * @var array<string, mixed>
     */
    protected array $schema = [];

    public function __construct(
        protected string $name,
        protected string $handler,
        protected string $method = 'POST'
    ) {
        $this->method = strtoupper($method);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function handler(): string
    {
        return $this->handler;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function middleware(string|array $middleware): self
    {
        $middlewares = is_array($middleware) ? $middleware : [$middleware];
        $this->middlewares = array_merge($this->middlewares, array_values(array_map('strval', $middlewares)));

        return $this;
    }

    /**
     * @return string[]
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function tags(string|array $tags): self
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $this->tags = array_values(array_unique(array_filter(array_map('strval', $tags))));

        return $this;
    }

    public function openapi(array $openapi): self
    {
        $this->openapi = array_replace_recursive($this->openapi, $openapi);

        return $this;
    }

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
            'summary' => $this->summary,
            'description' => $this->description,
            'tags' => $this->tags,
            'openapi' => $this->openapi,
            'schema' => $this->schema,
        ];
    }

    public function summaryText(): ?string
    {
        return $this->summary;
    }

    public function descriptionText(): ?string
    {
        return $this->description;
    }

    /**
     * @return string[]
     */
    public function tagList(): array
    {
        return $this->tags;
    }

    /**
     * @return array<string, mixed>
     */
    public function openapiSpec(): array
    {
        return $this->openapi;
    }

    /**
     * @return array<string, mixed>
     */
    public function schemaSpec(): array
    {
        return $this->schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'handler' => $this->handler,
            'method' => $this->method,
            'middlewares' => $this->middlewares,
            'meta' => $this->meta(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $definition = new self(
            (string) ($payload['name'] ?? ''),
            (string) ($payload['handler'] ?? ''),
            (string) ($payload['method'] ?? 'POST')
        );

        if (is_array($payload['middlewares'] ?? null)) {
            $definition->middleware($payload['middlewares']);
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        if (isset($meta['summary']) && is_string($meta['summary'])) {
            $definition->summary($meta['summary']);
        }

        if (isset($meta['description']) && is_string($meta['description'])) {
            $definition->description($meta['description']);
        }

        if (isset($meta['tags'])) {
            $definition->tags($meta['tags']);
        }

        if (isset($meta['openapi']) && is_array($meta['openapi'])) {
            $definition->openapi($meta['openapi']);
        }

        if (isset($meta['schema']) && is_array($meta['schema'])) {
            $definition->schema($meta['schema']);
        }

        return $definition;
    }
}