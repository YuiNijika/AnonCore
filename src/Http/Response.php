<?php

namespace Anon\Core\Http;

use JsonSerializable;

class Response
{
    /**
     * @var mixed 响应数据
     */
    protected mixed $data;

    /**
     * @var int HTTP状态码
     */
    protected int $statusCode;

    /**
     * @var array HTTP头信息
     */
    protected array $headers;

    /**
     * @var array<int, array{name: string, value: string, options: array<string, mixed>}>
     */
    protected array $cookies = [];

    public function __construct(mixed $data = null, int $statusCode = 200, array $headers = [])
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * 成功返回方法
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $statusCode = 200,
        string $code = 'OK',
        array $meta = [],
        array $links = []
    ): self {
        $payload = self::successEnvelope($data, $message, $code, $meta, $links);

        return new self($payload, $statusCode);
    }

    /**
     * 失败返回方法
     */
    public static function error(
        string $message = 'error',
        int $statusCode = 400,
        mixed $errors = null,
        string $code = 'ERROR',
        ?string $traceId = null,
        array $debug = []
    ): self {
        $payload = [
            'success' => false,
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== null && $errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($traceId !== null && $traceId !== '') {
            $payload['trace_id'] = $traceId;
        }

        if ($debug !== []) {
            $payload['debug'] = $debug;
        }

        return new self($payload, $statusCode);
    }

    /**
     * JSON格式的返回方法，保留原始输出能力
     */
    public static function json(mixed $data, int $statusCode = 200): self
    {
        return new self($data, $statusCode);
    }

    /**
     * 构建成功响应 envelope
     */
    public static function successEnvelope(
        mixed $data = null,
        string $message = 'OK',
        string $code = 'OK',
        array $meta = [],
        array $links = []
    ): array {
        $resolved = self::resolveResponseData($data);

        $payload = [
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $resolved['data'],
        ];

        $meta = array_replace_recursive($resolved['meta'], $meta);
        $links = array_replace_recursive($resolved['links'], $links);

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        if ($links !== []) {
            $payload['links'] = $links;
        }

        return $payload;
    }

    /**
     * 设置HTTP状态码
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * 获取当前状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 获取响应数据
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * 替换响应数据
     */
    public function setData(mixed $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 追加自定义 HTTP 头
     */
    public function setHeader(string $name, string $value): self
    {
        $name = str_replace(["\r", "\n"], '', $name);
        $value = str_replace(["\r", "\n"], '', $value);
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 追加自定义 HTTP 头的别名方法
     */
    public function withHeader(string $name, string $value): self
    {
        return $this->setHeader($name, $value);
    }

    /**
     * 设置 Cookie
     *
     * @param array<string, mixed> $options
     */
    public function setCookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'options' => $this->normalizeCookieOptions($options),
        ];

        return $this;
    }

    /**
     * 删除 Cookie
     *
     * @param array<string, mixed> $options
     */
    public function deleteCookie(string $name, array $options = []): self
    {
        $options['expires'] = time() - 3600;

        return $this->setCookie($name, '', $options);
    }

    /**
     * 发送HTTP响应
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        if (is_array($this->data) || is_object($this->data)) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo $this->data;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * @return array{data: mixed, meta: array, links: array}
     */
    protected static function resolveResponseData(mixed $data): array
    {
        $meta = [];
        $links = [];

        if (is_object($data) && method_exists($data, 'toResponsePayload')) {
            $payload = $data->toResponsePayload();

            return [
                'data' => $payload['data'] ?? null,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                'links' => is_array($payload['links'] ?? null) ? $payload['links'] : [],
            ];
        }

        if ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        return [
            'data' => $data,
            'meta' => $meta,
            'links' => $links,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function normalizeCookieOptions(array $options): array
    {
        return [
            'expires' => isset($options['expires']) ? (int) $options['expires'] : 0,
            'path' => (string) ($options['path'] ?? '/'),
            'domain' => (string) ($options['domain'] ?? ''),
            'secure' => (bool) ($options['secure'] ?? false),
            'httponly' => (bool) ($options['httponly'] ?? true),
            'samesite' => (string) ($options['samesite'] ?? 'Lax'),
        ];
    }
}
