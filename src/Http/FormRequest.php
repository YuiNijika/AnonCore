<?php

namespace Anon\Core\Http;

use Anon\Core\Facade\Validator;
use Anon\Core\Exception\Http;

abstract class FormRequest extends Request
{
    /**
     * 判断当前用户是否有权限发起此请求
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 定义验证规则
     */
    abstract public function rules(): array;

    /**
     * 自定义验证错误信息
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * 在路由注入后自动调用该方法进行验证
     */
    public function validateResolved(): void
    {
        if (!$this->authorize()) {
            throw Http::forbidden('This action is unauthorized.');
        }

        $rules = $this->rules();
        if (empty($rules)) {
            return;
        }

        $validator = Validator::make($this->validationData(), $rules, $this->messages());

        if ($validator->fails()) {
            throw Http::validation($validator->errors());
        }
    }

    /**
     * 获取参与验证的输入数据
     */
    public function validationData(): array
    {
        return array_merge($this->get, $this->post, is_array($this->body) ? $this->body : []);
    }
}
