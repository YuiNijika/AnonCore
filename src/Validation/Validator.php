<?php

namespace Anon\Core\Validation;

use Exception;

class Validator
{
    /**
     * @var array 待验证的数据
     */
    protected array $data;

    /**
     * @var array 验证规则
     */
    protected array $rules;

    /**
     * @var array 自定义错误消息
     */
    protected array $messages;

    /**
     * @var array 验证错误结果
     */
    protected array $errors = [];

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->rules = $this->parseRules($rules);
        $this->messages = $messages;
        
        $this->validate();
    }

    /**
     * 静态工厂方法
     */
    public static function make(array $data, array $rules, array $messages = []): static
    {
        return new static($data, $rules, $messages);
    }

    /**
     * 判断验证是否失败
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 获取所有错误信息
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 获取第一条错误信息
     */
    public function firstError(): ?string
    {
        if (empty($this->errors)) {
            return null;
        }
        $firstField = reset($this->errors);
        return reset($firstField);
    }

    /**
     * 解析规则
     */
    protected function parseRules(array $rules): array
    {
        $parsed = [];
        foreach ($rules as $field => $ruleStr) {
            if (is_string($ruleStr)) {
                $parsed[$field] = explode('|', $ruleStr);
            } elseif (is_array($ruleStr)) {
                $parsed[$field] = $ruleStr;
            }
        }
        return $parsed;
    }

    /**
     * 执行验证
     */
    protected function validate(): void
    {
        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                // 如果没有传值且规则不是 required，则跳过其他验证
                if ($value === null && $rule !== 'required') {
                    continue;
                }

                $this->applyRule($field, $value, $rule);
            }
        }
    }

    /**
     * 应用单条规则
     */
    protected function applyRule(string $field, mixed $value, string $rule): void
    {
        $params = [];
        $ruleName = $rule;

        if (str_contains($rule, ':')) {
            [$ruleName, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        }

        $method = 'validate' . ucfirst(strtolower($ruleName));

        if (!method_exists($this, $method)) {
            throw new Exception("Validation rule [{$ruleName}] is not supported.");
        }

        $passed = $this->$method($field, $value, $params);

        if (!$passed) {
            $this->addError($field, $ruleName, $params);
        }
    }

    /**
     * 记录错误
     */
    protected function addError(string $field, string $ruleName, array $params = []): void
    {
        $messageKey = "{$field}.{$ruleName}";
        if (isset($this->messages[$messageKey])) {
            $message = $this->messages[$messageKey];
        } else {
            $message = $this->getDefaultMessage($field, $ruleName, $params);
        }

        $this->errors[$field][] = $message;
    }

    /**
     * 获取默认错误信息
     */
    protected function getDefaultMessage(string $field, string $ruleName, array $params = []): string
    {
        $param0 = $params[0] ?? '';
        $messages = [
            'required' => "{$field} 不能为空",
            'email'    => "{$field} 格式不正确",
            'max'      => "{$field} 的最大长度/值不能超过 {$param0}",
            'min'      => "{$field} 的最小长度/值不能低于 {$param0}",
            'numeric'  => "{$field} 必须是数字",
            'integer'  => "{$field} 必须是整数",
            'in'       => "{$field} 必须在允许的范围内",
        ];

        return $messages[$ruleName] ?? "{$field} 格式验证失败";
    }

    // ------------------------------------------------------------------------
    // 内置验证规则实现
    // ------------------------------------------------------------------------

    protected function validateRequired(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) return false;
        if (is_string($value) && trim($value) === '') return false;
        if (is_array($value) && count($value) === 0) return false;
        return true;
    }

    protected function validateEmail(string $field, mixed $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validateMax(string $field, mixed $value, array $params): bool
    {
        $max = (float) $params[0];
        if (is_numeric($value)) {
            return $value <= $max;
        }
        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }
        if (is_array($value)) {
            return count($value) <= $max;
        }
        return false;
    }

    protected function validateMin(string $field, mixed $value, array $params): bool
    {
        $min = (float) $params[0];
        if (is_numeric($value)) {
            return $value >= $min;
        }
        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }
        if (is_array($value)) {
            return count($value) >= $min;
        }
        return false;
    }

    protected function validateNumeric(string $field, mixed $value, array $params): bool
    {
        return is_numeric($value);
    }

    protected function validateInteger(string $field, mixed $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    protected function validateIn(string $field, mixed $value, array $params): bool
    {
        return in_array((string)$value, $params, true);
    }
}