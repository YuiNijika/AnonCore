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

    /**
     * @var array 存放解析后的 UploadedFile 对象
     */
    protected array $files = [];

    public function __construct()
    {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->header = $this->parseHeaders();
        $this->body = $this->parseBody();
        $this->files = $this->parseFiles();
    }

    /**
     * 从另一个请求实例克隆数据
     */
    public function cloneFrom(Request $request): self
    {
        $this->get = $request->get;
        $this->post = $request->post;
        $this->server = $request->server;
        $this->header = $request->header;
        $this->body = $request->body;
        $this->files = $request->file();
        $this->routeParams = $request->getRouteParams();
        
        return $this;
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
     * 获取客户端 IP 地址
     * @return string
     */
    public function ip(): string
    {
        // 可能的IP来源数组
        $sources = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        ];

        foreach ($sources as $source) {
            if (!empty($this->server[$source])) {
                $ip = $this->server[$source];

                // 处理 X-Forwarded-For 可能包含多个 IP
                if ($source === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // 验证IP格式
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    // 将IPv6本地回环地址转换为IPv4格式
                    if ($ip === '::1') {
                        return '127.0.0.1';
                    }
                    return $ip;
                }
            }
        }

        // 所有来源都无法获取有效 IP 时返回默认值
        return '127.0.0.1';
    }

    /**
     * 合并输入数据并更新到实例
     */
    public function merge(array $input): self
    {
        $this->get = array_merge($this->get, $input);
        return $this;
    }
    /**
     * 获取指定名称的输入参数
     * @param string|null $key 参数名称
     * @param mixed $default 默认值
     * @return mixed
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_replace($this->get, $this->post, is_array($this->body) ? $this->body : []);
        }
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
     * 获取上传的文件 (返回 UploadedFile 实例或数组)
     * @param string|null $key
     * @return UploadedFile|UploadedFile[]|null
     */
    public function file(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->files;
        }
        return $this->files[$key] ?? null;
    }

    /**
     * 判断是否上传了指定文件且有效
     * @param string $key
     * @return bool
     */
    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        if ($file instanceof UploadedFile) {
            return $file->isValid();
        }
        if (is_array($file) && count($file) > 0) {
            foreach ($file as $f) {
                if ($f instanceof UploadedFile && $f->isValid()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 解析 $_FILES 为 UploadedFile 对象数组
     * @return array
     */
    protected function parseFiles(): array
    {
        $files = [];
        foreach ($_FILES as $key => $fileInfo) {
            // 处理单个字段上传多个文件的情况 <input type="file" name="files[]" multiple>
            if (is_array($fileInfo['name'])) {
                $files[$key] = [];
                foreach (array_keys($fileInfo['name']) as $idx) {
                    $files[$key][$idx] = new UploadedFile([
                        'name' => $fileInfo['name'][$idx],
                        'type' => $fileInfo['type'][$idx],
                        'tmp_name' => $fileInfo['tmp_name'][$idx],
                        'error' => $fileInfo['error'][$idx],
                        'size' => $fileInfo['size'][$idx],
                    ]);
                }
            } else {
                $files[$key] = new UploadedFile($fileInfo);
            }
        }
        return $files;
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
