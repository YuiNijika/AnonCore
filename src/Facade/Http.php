<?php

namespace Anon\Core\Facade;

use Anon\Core\Http\Client as HttpClient;

/**
 * @method static array get(string $url, array $query = [], array $headers = [])
 * @method static array post(string $url, mixed $data = [], array $headers = [])
 * @method static array put(string $url, mixed $data = [], array $headers = [])
 * @method static array delete(string $url, mixed $data = [], array $headers = [])
 * @method static array request(string $method, string $url, mixed $data = null, array $headers = [])
 */
class Http extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HttpClient::class;
    }
}
