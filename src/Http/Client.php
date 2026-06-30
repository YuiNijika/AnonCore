<?php

namespace Anon\Core\Http;

use Exception;

class Client
{
    /**
     * @var int 默认超时时间
     */
    protected int $timeout = 10;

    /**
     * @var bool 是否启用 SSL 证书校验，默认 true
     */
    protected ?bool $sslVerify = null;

    public function __construct()
    {
    }

    /**
     * 设置请求超时时间
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);
        return $this;
    }

    /**
     * 设置是否启用 SSL 证书校验
     */
    public function sslVerify(bool $verify): self
    {
        $this->sslVerify = $verify;
        return $this;
    }

    /**
     * 发送 GET 请求
     *
     * @param string $url
     * @param array $query
     * @param array $headers
     * @return array
     */
    public function get(string $url, array $query = [], array $headers = []): array
    {
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * 发送 POST 请求
     *
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return array
     */
    public function post(string $url, mixed $data = [], array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * 发送 PUT 请求
     *
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return array
     */
    public function put(string $url, mixed $data = [], array $headers = []): array
    {
        return $this->request('PUT', $url, $data, $headers);
    }

    /**
     * 发送 DELETE 请求
     *
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return array
     */
    public function delete(string $url, mixed $data = [], array $headers = []): array
    {
        return $this->request('DELETE', $url, $data, $headers);
    }

    /**
     * 发送 HTTP 请求
     *
     * @param string $method
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return array
     * @throws Exception
     */
    public function request(string $method, string $url, mixed $data = null, array $headers = []): array
    {
        if (!extension_loaded('curl')) {
            throw new Exception("The 'curl' extension is required for HTTP Client.");
        }

        $sslVerify = $this->sslVerify ?? (bool) \Anon\Core\Facade\Config::get('http.ssl_verify', true);

        $ch = curl_init();
        $method = strtoupper($method);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        // SSL 证书校验配置
        if (!$sslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($data !== null) {
            if (is_array($data)) {
                $hasFile = false;
                foreach ($data as $value) {
                    if ($value instanceof \CURLFile) {
                        $hasFile = true;
                        break;
                    }
                }
                
                if ($hasFile) {
                    // 如果有文件，使用 multipart/form-data
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                } else {
                    // 默认转为 JSON 请求
                    $payload = json_encode($data);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    $headers['Content-Type'] = 'application/json';
                    $headers['Content-Length'] = strlen($payload);
                }
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        // 处理请求头
        if (!empty($headers)) {
            $formattedHeaders = [];
            foreach ($headers as $key => $value) {
                if (is_numeric($key)) {
                    $formattedHeaders[] = $value;
                } else {
                    $formattedHeaders[] = "{$key}: {$value}";
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        curl_close($ch);

        if ($response === false) {
            throw new Exception("HTTP Client Error: {$error}");
        }

        $responseHeadersStr = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        return [
            'status' => $statusCode,
            'headers' => $this->parseHeaders($responseHeadersStr),
            'body' => $responseBody,
            'json' => json_decode($responseBody, true) // 尝试自动解析 JSON
        ];
    }

    /**
     * 解析响应头字符串为数组
     */
    protected function parseHeaders(string $headersStr): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($headersStr));
        
        // 第一行通常是 HTTP/1.1 200 OK，可以忽略或单独处理
        array_shift($lines);

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
