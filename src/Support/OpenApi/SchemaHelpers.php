<?php

namespace Anon\Core\Support\OpenApi;

use Anon\Core\Http\Resource\Json as JsonResource;

/**
 * RouteItem / Action Definition 共用的 OpenAPI schema 归一化逻辑。
 */
trait SchemaHelpers
{
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