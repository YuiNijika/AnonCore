<?php

namespace Anon\Core\Exception;

use Exception;
use Throwable;

class HttpException extends Exception
{
    /**
     * @var int HTTP 状态码
     */
    protected int $statusCode;

    /**
     * @var array 附加数据
     */
    protected array $data;

    public function __construct(int $statusCode, string $message = '', array $data = [], ?Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->data = $data;
        
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getData(): array
    {
        return $this->data;
    }
}