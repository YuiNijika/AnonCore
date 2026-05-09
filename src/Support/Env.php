<?php

namespace Anon\Core\Support;

class Env
{
    /**
     * @var array 存储解析后的环境变量
     */
    protected array $data = [];

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
                    $this->set($subKey, $subValue);
                }
            } else {
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
        // 兼容 putenv() 和 $_ENV 的读取
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }

        $systemEnv = getenv($key);
        if ($systemEnv !== false) {
            return $this->parseValue($systemEnv);
        }

        return isset($_ENV[$key]) ? $this->parseValue($_ENV[$key]) : $default;
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
        putenv("{$key}={$envValue}");
        $_ENV[$key] = $value;
    }

    /**
     * 解析环境变量的值（处理 bool 等类型）
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
}