<?php

namespace Anon\Core\Support;

class Env
{
    /**
     * @var array 存储解析后的环境变量
     */
    protected array $data = [];

    /**
     * @var array<string, bool> 记录外部注入且不应被 .env 覆盖的变量
     */
    protected array $protectedKeys = [];

    /**
     * 加载并解析 .env 文件
     * @param string $file .env 文件绝对路径
     */
    public function load(string $file): void
    {
        if (!file_exists($file)) {
            return;
        }

        $envData = parse_ini_file($file, true, INI_SCANNER_TYPED);
        if ($envData === false) {
            return;
        }

        // 扁平化处理，将 [SECTION] 下的变量提取出来
        foreach ($envData as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if ($this->shouldKeepSystemValue($subKey)) {
                        continue;
                    }
                    $this->set($subKey, $subValue);
                }
            } else {
                if ($this->shouldKeepSystemValue($key)) {
                    continue;
                }
                $this->set($key, $value);
            }
        }
    }

    /**
     * 获取环境变量
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 兼容 putenv 和 $_ENV 的读取
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }

        if (function_exists('getenv')) {
            $systemEnv = getenv($key);
            if ($systemEnv !== false) {
                return $this->parseValue($systemEnv);
            }
        }

        if (isset($_ENV[$key])) {
            return $this->parseValue($_ENV[$key]);
        }

        if (isset($_SERVER[$key])) {
            return $this->parseValue($_SERVER[$key]);
        }

        return $default;
    }

    /**
     * 设置环境变量
     * @param string $key 变量名
     * @param mixed $value 变量值
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        
        // 同步设置到 PHP 环境变量中
        $envValue = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        
        if (function_exists('putenv')) {
            putenv("{$key}={$envValue}");
        }
        
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * 解析环境变量的值
     * @param mixed $value
     * @return mixed
     */
    protected function parseValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $lowerValue = strtolower(trim($value));
            if ($lowerValue === 'true') return true;
            if ($lowerValue === 'false') return false;
            if ($lowerValue === 'null') return null;
        }
        return $value;
    }

    /**
     * 外部注入的环境变量优先级更高，不被 .env 文件覆盖。
     */
    protected function shouldKeepSystemValue(string $key): bool
    {
        if (isset($this->protectedKeys[$key])) {
            return true;
        }

        $systemValue = function_exists('getenv') ? getenv($key) : false;
        if (!array_key_exists($key, $this->data) && ($systemValue !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER))) {
            $this->protectedKeys[$key] = true;
            return true;
        }

        return false;
    }
}
