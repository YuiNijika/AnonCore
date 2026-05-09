<?php

namespace Anon\Core\Validation;

class Factory
{
    /**
     * 创建一个验证器实例
     */
    public function make(array $data, array $rules, array $messages = []): Validator
    {
        return new Validator($data, $rules, $messages);
    }
}