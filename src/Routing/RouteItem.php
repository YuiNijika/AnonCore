<?php

namespace Anon\Core\Routing;

use Anon\Core\Http\Resource\Json as JsonResource;

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

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $parameters = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $responses = [];

    /**
     * @var array<int, array<string, array<int, string>>>
     */
    public array $security = [];

    /**
     * @var bool|null
     */
    public ?bool $deprecated = null;

    /**
     * @var array<string, string>
     */
    public array $responseHeaders = [];

    /**
     * @var array<string, mixed>
     */
    public array $cors = [];

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
     * 声明 OpenAPI 参数。
     */
    public function parameter(
        string $name,
        string $in = 'query',
        array|string $schema = 'string',
        bool $required = false,
        ?string $description = null,
        mixed $example = null
    ): self {
        $location = in_array($in, ['query', 'path', 'header', 'cookie'], true) ? $in : 'query';
        $parameter = [
            'name' => $name,
            'in' => $location,
            'required' => $location === 'path' ? true : $required,
            'schema' => $this->normalizeSchemaDefinition($schema),
        ];

        if ($description !== null && $description !== '') {
            $parameter['description'] = $description;
        }

        if ($example !== null) {
            $parameter['example'] = $example;
        }

        return $this->storeParameter($parameter);
    }

    public function queryParam(
        string $name,
        array|string $schema = 'string',
        bool $required = false,
        ?string $description = null,
        mixed $example = null
    ): self {
        return $this->parameter($name, 'query', $schema, $required, $description, $example);
    }

    public function pathParam(
        string $name,
        array|string $schema = 'string',
        ?string $description = null,
        mixed $example = null
    ): self {
        return $this->parameter($name, 'path', $schema, true, $description, $example);
    }

    public function headerParam(
        string $name,
        array|string $schema = 'string',
        bool $required = false,
        ?string $description = null,
        mixed $example = null
    ): self {
        return $this->parameter($name, 'header', $schema, $required, $description, $example);
    }

    public function cookieParam(
        string $name,
        array|string $schema = 'string',
        bool $required = false,
        ?string $description = null,
        mixed $example = null
    ): self {
        return $this->parameter($name, 'cookie', $schema, $required, $description, $example);
    }

    /**
     * 声明响应。
     */
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

    /**
     * 使用统一成功响应契约声明返回结构。
     */
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

    /**
     * 使用统一错误响应契约声明返回结构。
     */
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

    /**
     * 基于 Json Resource 声明单资源成功响应。
     */
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

    /**
     * 基于 Json Resource 声明列表成功响应。
     */
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

    /**
     * 声明当前接口需要的安全方案。
     */
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

    /**
     * 标记接口是否废弃。
     */
    public function deprecated(bool $deprecated = true): self
    {
        $this->deprecated = $deprecated;

        return $this;
    }

    public function responseHeader(string $name, int|string|float|bool|null $value): self
    {
        $name = trim($name);
        if ($name === '' || $value === null) {
            return $this;
        }

        $this->responseHeaders[$name] = (string) $value;

        return $this;
    }

    /**
     * @param array<string, int|string|float|bool|null> $headers
     */
    public function responseHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $this->responseHeader($name, $value);
        }

        return $this;
    }

    public function allowHeaders(string|array $headers): self
    {
        $this->cors['allow_headers'] = $this->normalizeTokenList($headers);

        return $this;
    }

    public function exposeHeaders(string|array $headers): self
    {
        $this->cors['expose_headers'] = $this->normalizeTokenList($headers);

        return $this;
    }

    public function allowOrigin(string|array $origins = '*'): self
    {
        $this->cors['allow_origins'] = $this->normalizeTokenList($origins, false);

        return $this;
    }

    public function allowMethods(string|array $methods): self
    {
        $methods = $this->normalizeTokenList($methods);
        $this->cors['allow_methods'] = array_values(array_unique(array_map('strtoupper', $methods)));

        return $this;
    }

    public function allowCredentials(bool $allow = true): self
    {
        $this->cors['allow_credentials'] = $allow;

        return $this;
    }

    public function maxAge(int $seconds): self
    {
        $this->cors['max_age'] = max(0, $seconds);

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function cors(array $options): self
    {
        foreach ($options as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower(trim($key));
            switch ($normalizedKey) {
                case 'allow_headers':
                case 'headers':
                    if (is_string($value) || is_array($value)) {
                        $this->allowHeaders($value);
                    }
                    break;
                case 'expose_headers':
                    if (is_string($value) || is_array($value)) {
                        $this->exposeHeaders($value);
                    }
                    break;
                case 'allow_origins':
                case 'origins':
                case 'origin':
                    if (is_string($value) || is_array($value)) {
                        $this->allowOrigin($value);
                    }
                    break;
                case 'allow_methods':
                case 'methods':
                    if (is_string($value) || is_array($value)) {
                        $this->allowMethods($value);
                    }
                    break;
                case 'allow_credentials':
                case 'credentials':
                    $this->allowCredentials((bool) $value);
                    break;
                case 'max_age':
                    $this->maxAge((int) $value);
                    break;
                case 'response_headers':
                    if (is_array($value)) {
                        $this->responseHeaders($value);
                    }
                    break;
            }
        }

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
            'parameters' => $this->parameters,
            'responses' => $this->responses,
            'security' => $this->security,
            'deprecated' => $this->deprecated,
            'response_headers' => $this->responseHeaders,
            'cors' => $this->cors,
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
        $this->parameters = is_array($meta['parameters'] ?? null) ? array_values($meta['parameters']) : [];
        $this->responses = is_array($meta['responses'] ?? null) ? $meta['responses'] : [];
        $this->security = is_array($meta['security'] ?? null) ? array_values($meta['security']) : [];
        $this->deprecated = array_key_exists('deprecated', $meta) ? (bool) $meta['deprecated'] : null;
        $this->responseHeaders = is_array($meta['response_headers'] ?? null) ? $this->normalizeHeaderMap($meta['response_headers']) : [];
        $this->cors = is_array($meta['cors'] ?? null) ? $this->normalizeCorsMeta($meta['cors']) : [];

        return $this;
    }

    /**
     * @param array<string, mixed> $parameter
     */
    protected function storeParameter(array $parameter): self
    {
        $name = (string) ($parameter['name'] ?? '');
        $in = (string) ($parameter['in'] ?? 'query');

        foreach ($this->parameters as $index => $existing) {
            if (($existing['name'] ?? null) === $name && ($existing['in'] ?? null) === $in) {
                $this->parameters[$index] = array_replace_recursive($existing, $parameter);

                return $this;
            }
        }

        $this->parameters[] = $parameter;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeSchemaDefinition(array|string $schema): array
    {
        if (is_array($schema)) {
            if ($this->isOpenApiSchema($schema)) {
                return $schema;
            }

            if ($this->isListArray($schema)) {
                if ($schema === []) {
                    return [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ];
                }

                return [
                    'type' => 'array',
                    'items' => $this->normalizeSchemaDefinition($schema[0]),
                ];
            }

            $properties = [];
            $required = [];

            foreach ($schema as $name => $definition) {
                if (!is_string($name)) {
                    continue;
                }

                $property = $this->normalizeSchemaDefinition($definition);
                if (($property['required'] ?? false) === true) {
                    $required[] = $name;
                    unset($property['required']);
                }

                $properties[$name] = $property;
            }

            $resolved = [
                'type' => 'object',
                'properties' => $properties,
            ];

            if ($required !== []) {
                $resolved['required'] = $required;
            }

            return $resolved;
        }

        $parts = array_filter(array_map('trim', explode('|', $schema)));
        $resolved = ['type' => 'string'];

        foreach ($parts as $part) {
            if ($part === 'required') {
                $resolved['required'] = true;
                continue;
            }

            $resolved['type'] = match ($part) {
                'int', 'integer' => 'integer',
                'bool', 'boolean' => 'boolean',
                'float', 'double', 'number', 'numeric' => 'number',
                'array' => 'array',
                'object' => 'object',
                default => $resolved['type'],
            };
        }

        if (($resolved['type'] ?? 'string') === 'array' && !isset($resolved['items'])) {
            $resolved['items'] = ['type' => 'string'];
        }

        return $resolved;
    }

    /**
     * @param array<int|string, mixed>|string $values
     * @return string[]
     */
    protected function normalizeTokenList(array|string $values, bool $allowWildcard = true): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (!is_array($values)) {
            return [];
        }

        $tokens = [];
        foreach ($values as $value) {
            $token = trim((string) $value);
            if ($token === '') {
                continue;
            }

            if (!$allowWildcard && $token === '*') {
                $tokens[] = '*';
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    protected function normalizeHeaderMap(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || trim($name) === '' || $value === null) {
                continue;
            }

            $normalized[trim($name)] = (string) $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $cors
     * @return array<string, mixed>
     */
    protected function normalizeCorsMeta(array $cors): array
    {
        $normalized = [];

        if (isset($cors['allow_headers']) && (is_string($cors['allow_headers']) || is_array($cors['allow_headers']))) {
            $normalized['allow_headers'] = $this->normalizeTokenList($cors['allow_headers']);
        }

        if (isset($cors['expose_headers']) && (is_string($cors['expose_headers']) || is_array($cors['expose_headers']))) {
            $normalized['expose_headers'] = $this->normalizeTokenList($cors['expose_headers']);
        }

        if (isset($cors['allow_origins']) && (is_string($cors['allow_origins']) || is_array($cors['allow_origins']))) {
            $normalized['allow_origins'] = $this->normalizeTokenList($cors['allow_origins'], false);
        }

        if (isset($cors['allow_methods']) && (is_string($cors['allow_methods']) || is_array($cors['allow_methods']))) {
            $normalized['allow_methods'] = array_values(array_unique(array_map('strtoupper', $this->normalizeTokenList($cors['allow_methods']))));
        }

        if (array_key_exists('allow_credentials', $cors)) {
            $normalized['allow_credentials'] = (bool) $cors['allow_credentials'];
        }

        if (array_key_exists('max_age', $cors)) {
            $normalized['max_age'] = max(0, (int) $cors['max_age']);
        }

        return $normalized;
    }

    protected function isOpenApiSchema(array $schema): bool
    {
        foreach (['type', 'properties', 'items', '$ref', 'allOf', 'oneOf', 'anyOf', 'additionalProperties', 'enum'] as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function successEnvelopeSchema(
        array|string $dataSchema,
        string $description,
        int $statusCode
    ): array {
        $schema = [
            'allOf' => [
                ['$ref' => '#/components/schemas/ApiSuccess'],
            ],
        ];

        if ($dataSchema !== [] && $dataSchema !== '') {
            $schema['allOf'][] = [
                'type' => 'object',
                'properties' => [
                    'data' => $this->normalizeSchemaDefinition($dataSchema),
                    'code' => [
                        'type' => 'integer',
                        'example' => $statusCode,
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => $description,
                    ],
                ],
            ];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveResourceSchema(string $resourceClass): array
    {
        if (!class_exists($resourceClass) || !is_a($resourceClass, JsonResource::class, true)) {
            return [
                'type' => 'object',
                'additionalProperties' => true,
            ];
        }

        if (method_exists($resourceClass, 'schema')) {
            $schema = $resourceClass::schema();
            if (is_array($schema) && $schema !== []) {
                return $schema;
            }
        }

        return [
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveResourceCollectionSchema(string $resourceClass): array
    {
        if (!class_exists($resourceClass) || !is_a($resourceClass, JsonResource::class, true)) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ];
        }

        if (method_exists($resourceClass, 'collectionSchema')) {
            $schema = $resourceClass::collectionSchema();
            if (is_array($schema) && $schema !== []) {
                return $schema;
            }
        }

        return [
            'type' => 'array',
            'items' => $this->resolveResourceSchema($resourceClass),
        ];
    }

    protected function isListArray(array $value): bool
    {
        $expectedIndex = 0;

        foreach ($value as $key => $_) {
            if ($key !== $expectedIndex) {
                return false;
            }

            $expectedIndex++;
        }

        return true;
    }
}
