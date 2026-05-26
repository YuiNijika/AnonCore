<?php

namespace Anon\Core\Exception;

use Exception;
use Throwable;

class Http extends Exception
{
    /**
     * @var int HTTP 状态码
     */
    protected int $statusCode;

    /**
     * @var array 附加数据
     */
    protected array $data;

    /**
     * @var string 机器可读错误码
     */
    protected string $errorCode;

    public function __construct(
        int $statusCode,
        string $message = '',
        array $data = [],
        ?Throwable $previous = null,
        ?string $errorCode = null
    ) {
        $this->statusCode = $statusCode;
        $this->data = $data;
        $this->errorCode = $errorCode ?: self::defaultErrorCode($statusCode);

        parent::__construct($message, $statusCode, $previous);
    }

    public static function unauthorized(string $message = 'Unauthorized', array $data = []): self
    {
        return new self(401, $message, $data, null, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'Forbidden', array $data = []): self
    {
        return new self(403, $message, $data, null, 'FORBIDDEN');
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self(422, $message, $errors, null, 'VALIDATION_FAILED');
    }

    public static function tooManyRequests(string $message = 'Too Many Requests', array $data = []): self
    {
        return new self(429, $message, $data, null, 'TOO_MANY_REQUESTS');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    protected static function defaultErrorCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            default => $statusCode >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
    }
}