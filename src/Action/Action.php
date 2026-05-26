<?php

namespace Anon\Core\Action;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

abstract class Action
{
    /**
     * 返回当前 Action 使用的 FormRequest 类名。
     */
    public function request(): ?string
    {
        return null;
    }

    /**
     * 返回当前 Action 自带的中间件。
     *
     * @return string|array<int, string>
     */
    public function middleware(): string|array
    {
        return [];
    }

    /**
     * 返回当前请求是否允许执行这个 Action。
     */
    public function authorize(Request $request): bool
    {
        return true;
    }

    /**
     * 返回成功响应里的业务码。
     */
    public function code(mixed $result = null, ?Request $request = null): string
    {
        return 'OK';
    }

    /**
     * 返回成功响应里的 message。
     */
    public function message(mixed $result = null, ?Request $request = null): string
    {
        return 'OK';
    }

    /**
     * 返回成功响应使用的 HTTP 状态码。
     */
    public function statusCode(mixed $result = null, ?Request $request = null): int
    {
        return 200;
    }

    /**
     * 返回成功响应里的 meta。
     *
     * @return array<string, mixed>
     */
    public function meta(mixed $result = null, ?Request $request = null): array
    {
        return [];
    }

    /**
     * 返回成功响应里的 links。
     *
     * @return array<string, mixed>
     */
    public function links(mixed $result = null, ?Request $request = null): array
    {
        return [];
    }

    /**
     * 直接返回统一成功响应。
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    protected function success(
        mixed $data = null,
        string $message = 'OK',
        int $statusCode = 200,
        string $code = 'OK',
        array $meta = [],
        array $links = []
    ): Response {
        return Response::success($data, $message, $statusCode, $code, $meta, $links);
    }

    /**
     * 直接返回 201 Created。
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    protected function created(
        mixed $data = null,
        string $message = 'Created',
        array $meta = [],
        array $links = []
    ): Response {
        return $this->success($data, $message, 201, 'CREATED', $meta, $links);
    }

    /**
     * 直接返回 204 No Content。
     */
    protected function noContent(): Response
    {
        return Response::json(null, 204);
    }
}
