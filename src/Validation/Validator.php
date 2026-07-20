<?php

namespace Anon\Core\Validation;

use Anon\Core\Exception\Validation as ValidationError;

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
            $value = $this->getDataValue($field);
            $nullable = in_array('nullable', $rules, true);

            foreach ($rules as $rule) {
                $ruleName = $rule;
                if (is_string($rule) && str_contains($rule, ':')) {
                    $ruleName = explode(':', $rule, 2)[0];
                }

                if ($ruleName === 'nullable') {
                    continue;
                }

                // 空值且非 required：nullable 或 null 时跳过后续规则
                if ($this->isEmptyValue($value) && $ruleName !== 'required') {
                    if ($nullable || $value === null) {
                        continue;
                    }
                }

                if ($value === null && $ruleName !== 'required') {
                    continue;
                }

                $this->applyRule($field, $value, (string) $rule);
            }
        }
    }

    /**
     * 支持点号路径：address.city
     */
    protected function getDataValue(string $field): mixed
    {
        if (array_key_exists($field, $this->data)) {
            return $this->data[$field];
        }

        if (!str_contains($field, '.')) {
            return null;
        }

        $value = $this->data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    protected function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    /**
     * 应用单条规则
     */
    protected function applyRule(string $field, mixed $value, string $rule): void
    {
        $params = [];
        $ruleName = $rule;

        // regex: 之后整段都是模式，允许模式内包含冒号
        if (str_starts_with($rule, 'regex:')) {
            $ruleName = 'regex';
            $params = [substr($rule, 6)];
        } elseif (str_contains($rule, ':')) {
            [$ruleName, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        }

        $method = 'validate' . ucfirst(strtolower($ruleName));

        if (!method_exists($this, $method)) {
            throw new ValidationError("Validation rule [{$ruleName}] is not supported.");
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
        // 默认文案与框架异常信息统一为英文；业务侧可通过 messages() 覆盖
        $messages = [
            'required'  => "The {$field} field is required.",
            'email'     => "The {$field} field must be a valid email address.",
            'max'       => "The {$field} field must not be greater than {$param0}.",
            'min'       => "The {$field} field must be at least {$param0}.",
            'numeric'   => "The {$field} field must be a number.",
            'integer'   => "The {$field} field must be an integer.",
            'in'        => "The selected {$field} is invalid.",
            'array'     => "The {$field} field must be an array.",
            'date'      => "The {$field} field must be a valid date.",
            'confirmed' => "The {$field} field confirmation does not match.",
            'boolean'   => "The {$field} field must be true or false.",
            'regex'     => "The {$field} field format is invalid.",
            'alpha'     => "The {$field} field must only contain letters.",
            'alpha_num' => "The {$field} field must only contain letters and numbers.",
            'url'       => "The {$field} field must be a valid URL.",
            'ip'        => "The {$field} field must be a valid IP address.",
            'json'      => "The {$field} field must be a valid JSON string.",
            'same'      => "The {$field} field must match {$param0}.",
            'different' => "The {$field} field and {$param0} must be different.",
        ];

        return $messages[$ruleName] ?? "The {$field} field is invalid.";
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

    protected function validateArray(string $field, mixed $value, array $params): bool
    {
        return is_array($value);
    }

    protected function validateDate(string $field, mixed $value, array $params): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        try {
            new \DateTimeImmutable((string) $value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function validateConfirmed(string $field, mixed $value, array $params): bool
    {
        $other = $this->data[$field . '_confirmation'] ?? null;

        return (string) $value === (string) $other;
    }

    protected function validateBoolean(string $field, mixed $value, array $params): bool
    {
        return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }

    protected function validateNullable(string $field, mixed $value, array $params): bool
    {
        return true;
    }

    protected function validateRegex(string $field, mixed $value, array $params): bool
    {
        $pattern = $params[0] ?? '';
        if ($pattern === '' || (!is_string($value) && !is_numeric($value))) {
            return false;
        }

        // 允许用户写 ^...$ 或 /.../ 两种形式
        if ($pattern[0] !== '/') {
            $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
        }

        return @preg_match($pattern, (string) $value) === 1;
    }

    protected function validateAlpha(string $field, mixed $value, array $params): bool
    {
        return is_string($value) && preg_match('/^[\pL\pM]+$/u', $value) === 1;
    }

    protected function validateAlpha_num(string $field, mixed $value, array $params): bool
    {
        return is_string($value) && preg_match('/^[\pL\pM\pN]+$/u', $value) === 1;
    }

    protected function validateUrl(string $field, mixed $value, array $params): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function validateIp(string $field, mixed $value, array $params): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    protected function validateJson(string $field, mixed $value, array $params): bool
    {
        if (!is_string($value)) {
            return false;
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function validateSame(string $field, mixed $value, array $params): bool
    {
        $otherField = $params[0] ?? '';
        if ($otherField === '') {
            return false;
        }

        return (string) $value === (string) $this->getDataValue($otherField);
    }

    protected function validateDifferent(string $field, mixed $value, array $params): bool
    {
        $otherField = $params[0] ?? '';
        if ($otherField === '') {
            return false;
        }

        return (string) $value !== (string) $this->getDataValue($otherField);
    }
}