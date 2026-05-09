<?php

namespace Anon\Core\Http;

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

    public function __construct(mixed $data = null, int $statusCode = 200, array $headers = [])
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * 成功返回方法
     * @param mixed $data 响应数据
     * @param string $message 成功信息
     * @param int $code HTTP状态码
     * @return self
     */
    public static function success(mixed $data = null, string $message = 'success', int $code = 200): self
    {
        return new self([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * 失败返回方法
     * @param string $message 错误信息
     * @param int $code HTTP状态码
     * @param mixed $data 附加错误数据
     * @return self
     */
    public static function error(string $message = 'error', int $code = 400, mixed $data = null): self
    {
        return new self([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     *JSON格式的返回方法
     * @param mixed $data 响应数据
     * @param int $statusCode HTTP状态码
     * @return self
     */
    public static function json(mixed $data, int $statusCode = 200): self
    {
        return new self($data, $statusCode);
    }

    /**
     * 设置HTTP状态码
     * @param int $code HTTP状态码
     * @return self
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
     * 追加自定义 HTTP 头
     * @param string $name 头名称
     * @param string $value 头值
     * @return self
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 发送HTTP响应
     */
    public function send(): void
    {
        // 发送 HTTP 状态码
        http_response_code($this->statusCode);
        
        // 默认强制设置为 JSON 响应头符合 RESTful API 规范
        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        // 发送其他自定义头信息
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // 发送响应主体数据
        if (is_array($this->data) || is_object($this->data)) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo $this->data;
        }
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
