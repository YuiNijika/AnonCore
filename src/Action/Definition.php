<?php

namespace Anon\Core\Action;

use Anon\Core\Support\OpenApi\SchemaHelpers;

class Definition 
{
    use SchemaHelpers;

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

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $responses = [];

    /**
     * @var array<int, array<string, array<int, string>>>
     */
    protected array $security = [];

    protected ?bool $deprecated = null;

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

    public function response(
        int|string $statusCode,
        string $description = 'OK',
        array|string $schema = [],
        string $contentType = 'application/json'
    ): self {
        $response = [
            'description' => $description,
        ];

        if ($schema !== [] && $schema !== '') {
            $response['content'] = [
                $contentType => [
                    'schema' => $this->normalizeSchemaDefinition($schema),
                ],
            ];
        }

        $this->responses[(string) $statusCode] = $response;

        return $this;
    }

    public function successResponse(
        array|string $dataSchema = [],
        string $description = 'OK',
        int $statusCode = 200
    ): self {
        return $this->response($statusCode, $description, $this->successEnvelopeSchema(
            $dataSchema,
            $description,
            $statusCode
        ));
    }

    public function errorResponse(
        int|string $statusCode,
        string $description,
        int|string|null $errorCode = null,
        array|string $errorsSchema = []
    ): self {
        $schema = [
            'allOf' => [
                ['$ref' => '#/components/schemas/ApiError'],
            ],
        ];

        $overlay = [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'integer',
                    'example' => (int) $statusCode,
                ],
                'message' => [
                    'type' => 'string',
                    'example' => $description,
                ],
            ],
        ];

        if ($errorCode !== null && $errorCode !== '') {
            $overlay['properties']['error_code'] = [
                'type' => is_int($errorCode) || (is_string($errorCode) && is_numeric($errorCode)) ? 'integer' : 'string',
                'example' => is_int($errorCode) || (is_string($errorCode) && is_numeric($errorCode))
                    ? (int) $errorCode
                    : (string) $errorCode,
            ];
        }

        if ($errorsSchema !== [] && $errorsSchema !== '') {
            $overlay['properties']['errors'] = $this->normalizeSchemaDefinition($errorsSchema);
        }

        $schema['allOf'][] = $overlay;

        return $this->response($statusCode, $description, $schema);
    }

    public function resourceResponse(
        string $resourceClass,
        string $description = 'OK',
        int $statusCode = 200
    ): self {
        return $this->successResponse(
            $this->resolveResourceSchema($resourceClass),
            $description,
            $statusCode
        );
    }

    public function resourceCollectionResponse(
        string $resourceClass,
        string $description = 'OK',
        int $statusCode = 200,
        bool $paginated = false
    ): self {
        $schema = $this->successEnvelopeSchema(
            $this->resolveResourceCollectionSchema($resourceClass),
            $description,
            $statusCode
        );

        if ($paginated) {
            $schema['allOf'][] = [
                'type' => 'object',
                'properties' => [
                    'meta' => [
                        'allOf' => [
                            [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'pagination' => [
                                        '$ref' => '#/components/schemas/PaginationMeta',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'links' => [
                        '$ref' => '#/components/schemas/PaginationLinks',
                    ],
                ],
            ];
        }

        return $this->response($statusCode, $description, $schema);
    }

    public function security(string|array $requirements): self
    {
        if (is_string($requirements)) {
            $scheme = trim($requirements);
            if ($scheme !== '') {
                $this->security[] = [$scheme => []];
            }

            return $this;
        }

        if ($this->isListArray($requirements)) {
            $normalized = [];

            foreach ($requirements as $scheme) {
                if (!is_string($scheme)) {
                    continue;
                }

                $scheme = trim($scheme);
                if ($scheme === '') {
                    continue;
                }

                $normalized[$scheme] = [];
            }

            if ($normalized !== []) {
                $this->security[] = $normalized;
            }

            return $this;
        }

        $normalized = [];

        foreach ($requirements as $scheme => $scopes) {
            if (!is_string($scheme) || trim($scheme) === '') {
                continue;
            }

            if (is_string($scopes)) {
                $scopes = [$scopes];
            }

            if (!is_array($scopes)) {
                $scopes = [];
            }

            $normalized[trim($scheme)] = array_values(array_filter(array_map('strval', $scopes)));
        }

        if ($normalized !== []) {
            $this->security[] = $normalized;
        }

        return $this;
    }

    public function deprecated(bool $deprecated = true): self
    {
        $this->deprecated = $deprecated;

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
            'responses' => $this->responses,
            'security' => $this->security,
            'deprecated' => $this->deprecated,
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
     * @return array<string, array<string, mixed>>
     */
    public function responseSpecs(): array
    {
        return $this->responses;
    }

    /**
     * @return array<int, array<string, array<int, string>>>
     */
    public function securityRequirements(): array
    {
        return $this->security;
    }

    public function deprecatedState(): ?bool
    {
        return $this->deprecated;
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

        if (isset($meta['responses']) && is_array($meta['responses'])) {
            $definition->responses = $meta['responses'];
        }

        if (isset($meta['security']) && is_array($meta['security'])) {
            $definition->security = array_values($meta['security']);
        }

        if (array_key_exists('deprecated', $meta)) {
            $definition->deprecated((bool) $meta['deprecated']);
        }

        return $definition;
    }

}
