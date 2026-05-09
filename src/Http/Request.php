<?php

namespace Anon\Core\Http;

class Request
{
    /**
     * @var array GET请求参数
     */
    public array $get;

    /**
     * @var array POST请求参数
     */
    public array $post;

    /**
     * @var array SERVER环境变量
     */
    public array $server;

    /**
     * @var array HTTP头信息
     */
    public array $header;

    /**
     * @var mixed 请求体数据
     */
    public mixed $body;

    /**
     * @var array 路由参数
     */
    protected array $routeParams = [];

    public function __construct()
    {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->header = $this->parseHeaders();
        $this->body = $this->parseBody();
    }

    /**
     * 捕获当前HTTP请求并生成Request实例
     * @return self
     */
    public static function capture(): self
    {
        return new self();
    }

    /**
     * 获取请求方法
     * @return string
     */
    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * 获取请求 URI 路径并去除 Query 参数
     * @return string
     */
    public function uri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        if ($pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        return $uri;
    }

    /**
     * 获取指定名称的输入参数
     * @param string $key 参数名称
     * @param mixed $default 默认值
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (isset($this->get[$key])) {
            return $this->get[$key];
        }
        if (isset($this->post[$key])) {
            return $this->post[$key];
        }
        if (is_array($this->body) && isset($this->body[$key])) {
            return $this->body[$key];
        }
        return $default;
    }

    /**
     * 设置路由参数
     * @param array $params
     * @return $this
     */
    public function setRouteParams(array $params): self
    {
        $this->routeParams = $params;
        return $this;
    }

    /**
     * 获取路由参数
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->routeParams;
        }
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * 获取所有路由参数
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    /**
     * 获取请求头
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->header;
        }
        // 处理大小写不敏感
        foreach ($this->header as $k => $v) {
            if (strtolower($k) === strtolower($key)) {
                return $v;
            }
        }
        return $default;
    }

    /**
     * 获取 Bearer Token
     * @return string|null
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * 获取 Cookie 参数
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_COOKIE;
        }
        return $_COOKIE[$key] ?? $default;
    }

    /**
     * 获取上传的文件
     * @param string|null $key
     * @return mixed
     */
    public function file(?string $key = null): mixed
    {
        if ($key === null) {
            return $_FILES;
        }
        return $_FILES[$key] ?? null;
    }

    /**
     * 解析HTTP头信息

     * @return array
     */
    protected function parseHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($this->server as $name => $value) {
                if (str_starts_with($name, 'HTTP_')) {
                    $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }
        return $headers;
    }

    /**
     * 解析请求体
     * @return mixed
     */
    protected function parseBody(): mixed
    {
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            return null;
        }

        $contentType = $this->header['Content-Type'] ?? ($this->header['content-type'] ?? '');
        if (str_contains(strtolower($contentType), 'application/json')) {
            return json_decode($rawBody, true);
        }

        return $rawBody;
    }
}
