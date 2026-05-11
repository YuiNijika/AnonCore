<?php

namespace Anon\Core\Facade;

/**
 * 验证器外观类
 * 
 * @method static \Anon\Core\Validation\Validator make(array $data, array $rules, array $messages = [])
 */
class Validator extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'validator';
    }
}