<?php

namespace Anon\Core\Http;

use Anon\Core\Facade\Validator;
use Anon\Core\Exception\HttpException;

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
        // 1. 权限验证
        if (!$this->authorize()) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        // 2. 数据验证
        $rules = $this->rules();
        if (empty($rules)) {
            return;
        }

        $data = array_merge($this->get, $this->post, is_array($this->body) ? $this->body : []);
        $validator = Validator::make($data, $rules, $this->messages());

        if ($validator->fails()) {
            // 抛出 422 Unprocessable Entity
            throw new HttpException(422, 'Validation failed.', $validator->errors());
        }
    }
}
