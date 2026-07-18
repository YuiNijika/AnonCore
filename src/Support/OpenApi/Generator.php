<?php

namespace Anon\Core\Support\OpenApi;

use Anon\Core\Action\Definition as ActionDefinition;
use Anon\Core\Action\Registry as ActionRegistry;
use Anon\Core\Facade\Config;
use Anon\Core\Foundation\App;
use Anon\Core\Http\FormRequest;
use Anon\Core\Routing\RouteItem;
use Anon\Core\Routing\Router;

class Generator
{
    /**
     * @param array<string, array<string, RouteItem>> $routes
     * @return array<string, mixed>
     */
    public function generate(array $routes): array
    {
        $document = [
            'openapi' => $this->openapiVersion(),
            'info' => [
                'title' => App::NAME,
                'version' => App::VERSION,
            ],
            'paths' => [],
            'components' => $this->components(),
        ];

        foreach ($routes as $method => $items) {
            foreach ($items as $route) {
                if (!$route instanceof RouteItem) {
                    continue;
                }

                $path = $this->normalizePath($route->uri);
                $operation = $this->operation($route);

                $document['paths'][$path][strtolower($method)] = $this->mergeOperation(
                    $operation,
                    $route->openapi
                );
            }
        }

        $this->appendActions($document);
        ksort($document['paths']);

        return $document;
    }

    /**
     * @return string[]
     */
    public function routeDocumentationIssues(RouteItem $route): array
    {
        return $this->issueMessages($this->routeDocumentationIssueDetails($route));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function routeDocumentationIssueDetails(RouteItem $route): array
    {
        $issues = [];

        if (!$this->hasRouteSummary($route)) {
            $issues[] = $this->issueDetail(
                'missing_summary',
                'missing summary()',
                ['field' => 'summary']
            );
        }

        if (!$this->hasRouteCustomResponses($route)) {
            $issues[] = $this->issueDetail(
                'default_response_only',
                'default 200 response only',
                ['field' => 'responses']
            );
        }

        $unresolvedPathParameters = $this->unresolvedPathParameters($route);
        if ($unresolvedPathParameters !== []) {
            $issues[] = $this->issueDetail(
                'unresolved_path_parameters',
                'unresolved path parameters: ' . implode(', ', $unresolvedPathParameters),
                [
                    'field' => 'parameters',
                    'parameters' => $unresolvedPathParameters,
                ]
            );
        }

        return $issues;
    }

    /**
     * @return string[]
     */
    public function actionDocumentationIssues(ActionDefinition $action): array
    {
        return $this->issueMessages($this->actionDocumentationIssueDetails($action));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function actionDocumentationIssueDetails(ActionDefinition $action): array
    {
        $issues = [];

        if (!$this->hasActionSummary($action)) {
            $issues[] = $this->issueDetail(
                'missing_summary',
                'missing summary()',
                ['field' => 'summary']
            );
        }

        if (!$this->hasActionCustomResponses($action)) {
            $issues[] = $this->issueDetail(
                'missing_explicit_response_metadata',
                'missing explicit response metadata',
                ['field' => 'responses']
            );
        }

        return $issues;
    }

    /**
     * @return string[]
     */
    public function unresolvedPathParameters(RouteItem $route): array
    {
        if (!preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $route->uri, $matches)) {
            return [];
        }

        $unresolved = [];

        foreach ($matches[1] as $name) {
            if ($this->hasExplicitPathParameter($route, $name)) {
                continue;
            }

            if ($this->inferPathParameterTypeFromReflection($route, $name) !== null) {
                continue;
            }

            if ($this->inferPathParameterTypeFromBinding($name) !== null) {
                continue;
            }

            $unresolved[] = $name;
        }

        return array_values(array_unique($unresolved));
    }

    public function hasRouteSummary(RouteItem $route): bool
    {
        if ($route->summary !== null && trim($route->summary) !== '') {
            return true;
        }

        $summary = $route->openapi['summary'] ?? null;

        return is_string($summary) && trim($summary) !== '';
    }

    public function hasRouteCustomResponses(RouteItem $route): bool
    {
        if ($route->responses !== []) {
            return true;
        }

        return is_array($route->openapi['responses'] ?? null) && $route->openapi['responses'] !== [];
    }

    public function hasActionSummary(ActionDefinition $action): bool
    {
        if (($action->summaryText() ?? '') !== '') {
            return true;
        }

        $summary = $action->openapiSpec()['summary'] ?? null;

        return is_string($summary) && trim($summary) !== '';
    }

    public function hasActionCustomResponses(ActionDefinition $action): bool
    {
        if ($action->responseSpecs() !== []) {
            return true;
        }

        return is_array($action->openapiSpec()['responses'] ?? null) && $action->openapiSpec()['responses'] !== [];
    }

    /**
     * @param array<int, array<string, mixed>> $details
     * @return string[]
     */
    protected function issueMessages(array $details): array
    {
        $messages = [];

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $message = $detail['message'] ?? null;
            if (!is_string($message) || $message === '') {
                continue;
            }

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function issueDetail(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'message' => $message,
            'severity' => 'warning',
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    protected function operation(RouteItem $route): array
    {
        $operation = [
            'operationId' => $route->name ?: $this->operationId($route),
            'responses' => [
                '200' => [
                    'description' => 'OK',
                ],
            ],
        ];

        if ($route->summary !== null) {
            $operation['summary'] = $route->summary;
        }

        if ($route->description !== null) {
            $operation['description'] = $route->description;
        }

        if ($route->tags !== []) {
            $operation['tags'] = $route->tags;
        }

        $inferredInput = $this->inferRouteInput($route);
        $parameters = $this->mergeParameters(
            $this->parameters($route),
            array_merge($inferredInput['parameters'], $route->parameters)
        );
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($route->schema !== []) {
            $operation['requestBody'] = $this->requestBody($route->schema);
        } elseif ($inferredInput['requestBody'] !== null) {
            $operation['requestBody'] = $inferredInput['requestBody'];
        }

        if ($route->responses !== []) {
            $operation['responses'] = array_replace_recursive(
                $operation['responses'],
                $this->normalizeResponses($route->responses)
            );
        }

        if ($route->security !== []) {
            $operation['security'] = $this->normalizeSecurityRequirements($route->security);
        }

        if ($route->deprecated !== null) {
            $operation['deprecated'] = $route->deprecated;
        }

        return $operation;
    }

    protected function normalizePath(string $uri): string
    {
        return preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', '{$1}', $uri) ?: $uri;
    }

    protected function operationId(RouteItem $route): string
    {
        $base = strtolower($route->method) . '_' . trim($route->uri, '/');
        $base = preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', 'by_$1', $base) ?: $base;
        $base = preg_replace('/[^A-Za-z0-9_]+/', '_', $base) ?: $base;
        $base = trim($base, '_');

        return $base !== '' ? $base : strtolower($route->method) . '_root';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parameters(RouteItem $route): array
    {
        $uri = $route->uri;
        if (!preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $uri, $matches)) {
            return [];
        }

        $parameters = [];
        foreach ($matches[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $this->inferPathParameterSchema($route, $name),
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    protected function inferPathParameterSchema(RouteItem $route, string $name): array
    {
        $schema = $this->inferPathParameterTypeFromReflection($route, $name);
        if ($schema !== null) {
            return $schema;
        }

        $schema = $this->inferPathParameterTypeFromBinding($name);
        if ($schema !== null) {
            return $schema;
        }

        return [
            'type' => 'string',
        ];
    }

    protected function hasExplicitPathParameter(RouteItem $route, string $name): bool
    {
        foreach ($route->parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            if (($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $name) {
                return true;
            }
        }

        $parameters = $route->openapi['parameters'] ?? null;
        if (!is_array($parameters)) {
            return false;
        }

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            if (($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function inferPathParameterTypeFromReflection(RouteItem $route, string $name): ?array
    {
        $reflect = $this->resolveRouteActionReflection($route);
        if (!$reflect instanceof \ReflectionMethod) {
            return null;
        }

        foreach ($reflect->getParameters() as $parameter) {
            if ($parameter->getName() !== $name) {
                continue;
            }

            return $this->schemaFromReflectionType($parameter->getType());
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function inferPathParameterTypeFromBinding(string $name): ?array
    {
        $router = $this->resolveRouter();
        if (!$router instanceof Router) {
            return null;
        }

        $bindings = $router->getBindings();
        $binding = $bindings[$name] ?? null;
        if (!is_array($binding) || !isset($binding['key'])) {
            return null;
        }

        $key = strtolower(trim((string) $binding['key']));
        if ($key === 'id' || str_ends_with($key, '_id')) {
            return ['type' => 'integer'];
        }

        if ($key === 'uuid' || str_ends_with($key, '_uuid')) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        return ['type' => 'string'];
    }

    protected function resolveRouter(): ?Router
    {
        try {
            $router = App::getInstance()->make('router');
        } catch (\Throwable) {
            return null;
        }

        return $router instanceof Router ? $router : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function schemaFromReflectionType(?\ReflectionType $type): ?array
    {
        if (!$type instanceof \ReflectionNamedType || $type->allowsNull() || !$type->isBuiltin()) {
            return null;
        }

        return match ($type->getName()) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function appendActions(array &$document): void
    {
        try {
            $registry = App::getInstance()->make('action.registry');
        } catch (\Throwable) {
            return;
        }

        if (!$registry instanceof ActionRegistry) {
            return;
        }

        $basePath = '/' . trim((string) Config::get('actions.path', '/_actions'), '/');
        if ($basePath === '/') {
            $basePath = '/_actions';
        }

        foreach ($registry->all() as $action) {
            if (!$action instanceof ActionDefinition) {
                continue;
            }

            $path = $basePath . '/' . $action->name();
            $operation = $this->actionOperation($action);
            $document['paths'][$path][strtolower($action->method())] = $this->mergeOperation(
                $operation,
                $action->openapiSpec()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionOperation(ActionDefinition $action): array
    {
        $operation = [
            'operationId' => 'action_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $action->name()),
            'tags' => $action->tagList() !== [] ? $action->tagList() : ['Server Actions'],
            'summary' => $action->summaryText() ?: 'Call server action ' . $action->name(),
            'responses' => [
                '200' => [
                    'description' => 'OK',
                ],
                '401' => [
                    'description' => 'Unauthorized.',
                ],
                '403' => [
                    'description' => 'Forbidden.',
                ],
                '419' => [
                    'description' => 'CSRF token mismatch.',
                ],
                '422' => [
                    'description' => 'Validation failed.',
                ],
                '429' => [
                    'description' => 'Too Many Requests.',
                ],
            ],
        ];

        if ($action->schemaSpec() !== []) {
            $operation['requestBody'] = $this->requestBody($action->schemaSpec());
        } elseif (($inferredRequestBody = $this->inferActionRequestBody($action)) !== null) {
            $operation['requestBody'] = $inferredRequestBody;
        } else {
            $operation['requestBody'] = $this->requestBody([]);
        }

        if ($action->descriptionText() !== null) {
            $operation['description'] = $action->descriptionText();
        }

        if ($action->responseSpecs() !== []) {
            $operation['responses'] = array_replace_recursive(
                $operation['responses'],
                $this->normalizeResponses($action->responseSpecs())
            );
        }

        if ($action->securityRequirements() !== []) {
            $operation['security'] = $this->normalizeSecurityRequirements($action->securityRequirements());
        }

        if ($action->deprecatedState() !== null) {
            $operation['deprecated'] = $action->deprecatedState();
        }

        return $operation;
    }

    /**
     * @return array{parameters: array<int, array<string, mixed>>, requestBody: array<string, mixed>|null}
     */
    protected function inferRouteInput(RouteItem $route): array
    {
        $reflect = $this->resolveRouteActionReflection($route);
        if (!$reflect instanceof \ReflectionMethod) {
            return [
                'parameters' => [],
                'requestBody' => null,
            ];
        }

        foreach ($reflect->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            if (!is_a($className, FormRequest::class, true)) {
                continue;
            }

            return $this->inferFormRequestInput($className, $route->method);
        }

        return [
            'parameters' => [],
            'requestBody' => null,
        ];
    }

    protected function resolveRouteActionReflection(RouteItem $route): ?\ReflectionMethod
    {
        $action = $route->action;

        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;

            if (is_string($class) && class_exists($class) && is_string($method) && method_exists($class, $method)) {
                return new \ReflectionMethod($class, $method);
            }

            return null;
        }

        if (!is_string($action) || !str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);
        $class = str_replace(['/', '\\'], '\\', trim($class));
        if (!str_starts_with($class, 'Anon\\Controller\\')) {
            $class = 'Anon\\Controller\\' . ltrim($class, '\\');
        }

        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return new \ReflectionMethod($class, $method);
    }

    /**
     * @return array{parameters: array<int, array<string, mixed>>, requestBody: array<string, mixed>|null}
     */
    protected function inferFormRequestInput(string $className, string $method): array
    {
        $request = $this->makeFormRequestInstance($className);
        if (!$request instanceof FormRequest) {
            return [
                'parameters' => [],
                'requestBody' => null,
            ];
        }

        try {
            $rules = $request->rules();
        } catch (\Throwable) {
            return [
                'parameters' => [],
                'requestBody' => null,
            ];
        }

        if (!is_array($rules) || $rules === []) {
            return [
                'parameters' => [],
                'requestBody' => null,
            ];
        }

        $schema = $this->rulesToObjectSchema($rules);
        $method = strtoupper($method);

        if (in_array($method, ['GET', 'DELETE', 'HEAD'], true)) {
            return [
                'parameters' => $this->rulesToQueryParameters($rules),
                'requestBody' => null,
            ];
        }

        return [
            'parameters' => [],
            'requestBody' => $this->requestBody($schema),
        ];
    }

    protected function inferActionRequestBody(ActionDefinition $action): ?array
    {
        $handler = $action->handler();
        if (!class_exists($handler)) {
            return null;
        }

        try {
            $instance = new $handler();
        } catch (\Throwable) {
            return null;
        }

        if (!method_exists($instance, 'request')) {
            return null;
        }

        try {
            $requestClass = $instance->request();
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($requestClass) || $requestClass === '' || !is_a($requestClass, FormRequest::class, true)) {
            return null;
        }

        $inferred = $this->inferFormRequestInput($requestClass, $action->method());

        return $inferred['requestBody'];
    }

    protected function makeFormRequestInstance(string $className): ?FormRequest
    {
        if (!class_exists($className) || !is_a($className, FormRequest::class, true)) {
            return null;
        }

        try {
            /** @var FormRequest $instance */
            $instance = new $className();

            return $instance;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $extension
     * @return array<string, mixed>
     */
    protected function mergeOperation(array $operation, array $extension): array
    {
        foreach ($extension as $key => $value) {
            if ($key === 'parameters' && is_array($value)) {
                $existing = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
                $operation['parameters'] = $this->mergeParameters($existing, $value);
                continue;
            }

            if ($key === 'responses' && is_array($value)) {
                $existing = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
                $operation['responses'] = array_replace_recursive($existing, $this->normalizeResponses($value));
                continue;
            }

            if ($key === 'security' && is_array($value)) {
                $operation['security'] = $this->normalizeSecurityRequirements($value);
                continue;
            }

            if ($key === 'requestBody' && is_array($value)) {
                $operation['requestBody'] = $this->normalizeRequestBody($value);
                continue;
            }

            if (is_array($value) && is_array($operation[$key] ?? null)) {
                $operation[$key] = array_replace_recursive($operation[$key], $value);
                continue;
            }

            $operation[$key] = $value;
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestBody(array $schema): array
    {
        return $this->normalizeRequestBody([
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => $schema === []
                        ? [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ]
                        : $this->objectSchema($schema),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function objectSchema(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
            return $schema;
        }

        $required = [];
        $properties = [];

        foreach ($schema as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            $property = $this->propertySchema($definition);
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

    /**
     * @param array<string, string|array<int, string>> $rules
     * @return array<string, mixed>
     */
    protected function rulesToObjectSchema(array $rules): array
    {
        $required = [];
        $properties = [];

        foreach ($rules as $field => $definition) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $property = $this->validationRuleSchema($definition);
            if (($property['required'] ?? false) === true) {
                $required[] = $field;
                unset($property['required']);
            }

            $properties[$field] = $property;
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array<string, string|array<int, string>> $rules
     * @return array<int, array<string, mixed>>
     */
    protected function rulesToQueryParameters(array $rules): array
    {
        $parameters = [];

        foreach ($rules as $field => $definition) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $schema = $this->validationRuleSchema($definition);
            $required = (bool) ($schema['required'] ?? false);
            unset($schema['required']);

            $parameters[] = [
                'name' => $field,
                'in' => 'query',
                'required' => $required,
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /**
     * @param string|array<int, string> $definition
     * @return array<string, mixed>
     */
    protected function validationRuleSchema(string|array $definition): array
    {
        $rules = is_array($definition) ? $definition : explode('|', $definition);
        $rules = array_values(array_filter(array_map('strval', $rules)));
        $schema = ['type' => 'string'];

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            $name = $rule;
            $params = [];
            if (str_contains($rule, ':')) {
                [$name, $paramString] = explode(':', $rule, 2);
                $params = array_values(array_filter(array_map('trim', explode(',', $paramString))));
            }

            $name = strtolower(trim($name));

            switch ($name) {
                case 'required':
                    $schema['required'] = true;
                    break;
                case 'email':
                    $schema['type'] = 'string';
                    $schema['format'] = 'email';
                    break;
                case 'integer':
                case 'int':
                    $schema['type'] = 'integer';
                    break;
                case 'numeric':
                case 'number':
                case 'float':
                case 'double':
                    $schema['type'] = 'number';
                    break;
                case 'boolean':
                case 'bool':
                    $schema['type'] = 'boolean';
                    break;
                case 'array':
                    $schema['type'] = 'array';
                    $schema['items'] = $schema['items'] ?? ['type' => 'string'];
                    break;
                case 'object':
                    $schema['type'] = 'object';
                    $schema['additionalProperties'] = true;
                    break;
                case 'in':
                    if ($params !== []) {
                        $schema['enum'] = array_values($params);
                    }
                    break;
                case 'min':
                    if (isset($params[0]) && is_numeric($params[0])) {
                        $this->applyNumericLimit($schema, 'min', $params[0]);
                    }
                    break;
                case 'max':
                    if (isset($params[0]) && is_numeric($params[0])) {
                        $this->applyNumericLimit($schema, 'max', $params[0]);
                    }
                    break;
            }
        }

        if (($schema['type'] ?? 'string') === 'array' && !isset($schema['items'])) {
            $schema['items'] = ['type' => 'string'];
        }

        return $schema;
    }

    protected function applyNumericLimit(array &$schema, string $kind, string $value): void
    {
        $number = str_contains($value, '.') ? (float) $value : (int) $value;
        $type = $schema['type'] ?? 'string';

        if (in_array($type, ['integer', 'number'], true)) {
            $schema[$kind === 'min' ? 'minimum' : 'maximum'] = $number;
            return;
        }

        if ($type === 'array') {
            $schema[$kind === 'min' ? 'minItems' : 'maxItems'] = (int) $number;
            return;
        }

        $schema[$kind === 'min' ? 'minLength' : 'maxLength'] = (int) $number;
    }

    /**
     * @return array<string, mixed>
     */
    protected function propertySchema(mixed $definition): array
    {
        if (is_array($definition)) {
            if (isset($definition['schema']) && is_array($definition['schema'])) {
                $definition['schema'] = $this->normalizeSchemaNode($definition['schema']);
            }

            return $definition;
        }

        if (!is_string($definition)) {
            return ['type' => 'string'];
        }

        $parts = array_filter(array_map('trim', explode('|', $definition)));
        $schema = ['type' => 'string'];

        foreach ($parts as $part) {
            if ($part === 'required') {
                $schema['required'] = true;
                continue;
            }

            $schema['type'] = match ($part) {
                'int', 'integer' => 'integer',
                'bool', 'boolean' => 'boolean',
                'float', 'double', 'number', 'numeric' => 'number',
                'array' => 'array',
                'object' => 'object',
                default => $schema['type'],
            };
        }

        if (($schema['type'] ?? 'string') === 'array' && !isset($schema['items'])) {
            $schema['items'] = ['type' => 'string'];
        }

        return $schema;
    }

    /**
     * @param array<int, array<string, mixed>> $autoParameters
     * @param array<int, array<string, mixed>> $customParameters
     * @return array<int, array<string, mixed>>
     */
    protected function mergeParameters(array $autoParameters, array $customParameters): array
    {
        $indexed = [];

        foreach (array_merge($autoParameters, $customParameters) as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = (string) ($parameter['name'] ?? '');
            $in = (string) ($parameter['in'] ?? 'query');
            if ($name === '') {
                continue;
            }

            if (isset($parameter['schema']) && is_array($parameter['schema'])) {
                $parameter['schema'] = $this->normalizeSchemaNode($parameter['schema']);
            }

            $indexed[$in . ':' . $name] = $parameter;
        }

        return array_values($indexed);
    }

    /**
     * @param array<string, array<string, mixed>> $responses
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeResponses(array $responses): array
    {
        foreach ($responses as $statusCode => $response) {
            if (!is_array($response)) {
                continue;
            }

            if (isset($response['content']) && is_array($response['content'])) {
                foreach ($response['content'] as $contentType => $content) {
                    if (!is_array($content)) {
                        continue;
                    }

                    if (isset($content['schema']) && is_array($content['schema'])) {
                        $response['content'][$contentType]['schema'] = $this->normalizeSchemaNode($content['schema']);
                    }
                }
            }

            $responses[(string) $statusCode] = $response;
        }

        return $responses;
    }

    /**
     * @param array<string, mixed> $requestBody
     * @return array<string, mixed>
     */
    protected function normalizeRequestBody(array $requestBody): array
    {
        if (!is_array($requestBody['content'] ?? null)) {
            return $requestBody;
        }

        foreach ($requestBody['content'] as $contentType => $content) {
            if (!is_array($content)) {
                continue;
            }

            if (isset($content['schema']) && is_array($content['schema'])) {
                $requestBody['content'][$contentType]['schema'] = $this->normalizeSchemaNode($content['schema']);
            }
        }

        return $requestBody;
    }

    /**
     * @param array<int|string, mixed> $requirements
     * @return array<int, array<string, array<int, string>>>
     */
    protected function normalizeSecurityRequirements(array $requirements): array
    {
        if ($requirements === []) {
            return [];
        }

        if (!$this->isListArray($requirements)) {
            $requirements = [$requirements];
        }

        $normalized = [];

        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }

            $entry = [];
            foreach ($requirement as $scheme => $scopes) {
                if (!is_string($scheme) || trim($scheme) === '') {
                    continue;
                }

                if (is_string($scopes)) {
                    $scopes = [$scopes];
                }

                if (!is_array($scopes)) {
                    $scopes = [];
                }

                $entry[trim($scheme)] = array_values(array_filter(array_map('strval', $scopes)));
            }

            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
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

    /**
     * @return array<string, mixed>
     */
    protected function normalizeSchemaNode(array $schema): array
    {
        if (isset($schema['$ref']) || isset($schema['allOf']) || isset($schema['oneOf']) || isset($schema['anyOf'])) {
            foreach (['allOf', 'oneOf', 'anyOf'] as $key) {
                if (!is_array($schema[$key] ?? null)) {
                    continue;
                }

                foreach ($schema[$key] as $index => $item) {
                    if (is_array($item)) {
                        $schema[$key][$index] = $this->normalizeSchemaNode($item);
                    }
                }
            }

            if (isset($schema['properties']) && is_array($schema['properties'])) {
                foreach ($schema['properties'] as $name => $definition) {
                    if (is_array($definition)) {
                        $schema['properties'][$name] = $this->normalizeSchemaNode($definition);
                    }
                }
            }

            return $schema;
        }

        if (isset($schema['type']) || isset($schema['properties']) || isset($schema['items'])) {
            if (isset($schema['properties']) && is_array($schema['properties'])) {
                foreach ($schema['properties'] as $name => $definition) {
                    if (is_array($definition)) {
                        $schema['properties'][$name] = $this->normalizeSchemaNode($definition);
                    }
                }
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                $schema['items'] = $this->normalizeSchemaNode($schema['items']);
            }

            return $schema;
        }

        return $this->objectSchema($schema);
    }

    /**
     * @return array<string, mixed>
     */
    protected function components(): array
    {
        return [
            'schemas' => [
                'ApiSuccess' => [
                    'type' => 'object',
                    'required' => ['success', 'code', 'message', 'data'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => true],
                        'code' => ['type' => 'integer', 'example' => 200],
                        'message' => ['type' => 'string', 'example' => 'OK'],
                        'data' => ['nullable' => true],
                        'business_code' => ['type' => 'string', 'nullable' => true],
                        'meta' => ['type' => 'object', 'additionalProperties' => true],
                        'links' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
                'ApiError' => [
                    'type' => 'object',
                    'required' => ['success', 'code', 'message'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => false],
                        'code' => ['type' => 'integer', 'example' => 400],
                        'message' => ['type' => 'string'],
                        'error_code' => ['type' => 'string', 'nullable' => true, 'example' => 'VALIDATION_FAILED'],
                        'errors' => ['nullable' => true],
                        'trace_id' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'PaginationMeta' => [
                    'type' => 'object',
                    'properties' => [
                        'current_page' => ['type' => 'integer', 'example' => 1],
                        'per_page' => ['type' => 'integer', 'example' => 15],
                        'total' => ['type' => 'integer', 'example' => 120],
                        'last_page' => ['type' => 'integer', 'example' => 8],
                        'from' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                        'to' => ['type' => 'integer', 'nullable' => true, 'example' => 15],
                    ],
                ],
                'PaginationLinks' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => ['type' => 'string', 'nullable' => true, 'example' => '/users?page=1&per_page=15'],
                        'last' => ['type' => 'string', 'nullable' => true, 'example' => '/users?page=8&per_page=15'],
                        'prev' => ['type' => 'string', 'nullable' => true, 'example' => null],
                        'next' => ['type' => 'string', 'nullable' => true, 'example' => '/users?page=2&per_page=15'],
                    ],
                ],
            ],
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
                'apiKeyAuth' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-API-Key',
                ],
            ],
        ];
    }

    /**
     * 从配置读取 OpenAPI 规范版本，默认 '3.0.3'。
     *
     * 仅允许 3.0.x 和 3.1.x 系列；非法值退回默认。
     */
    private function openapiVersion(): string
    {
        $version = trim((string) Config::get('openapi.version', '3.0.3'));

        // 白名单校验 必须匹配 3.0.N 或 3.1.N
        if (!preg_match('/^3\.[01]\.\d+$/', $version)) {
            return '3.0.3';
        }

        return $version;
    }
}
