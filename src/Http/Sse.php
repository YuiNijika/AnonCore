<?php

declare(strict_types=1);

namespace Anon\Core\Http;

final class Sse
{
    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding'  => 'identity',
        ];
    }

    public static function prepare(): void
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    public static function connected(): bool
    {
        if (connection_aborted()) {
            return false;
        }

        return connection_status() === CONNECTION_NORMAL;
    }

    public static function flush(string $chunk): void
    {
        if (!self::connected()) {
            return;
        }

        echo $chunk;
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    /** 反代/CDN 常因长时间无首字节断连，先刷填充包 */
    public static function kickoff(int $paddingBytes = 2048): void
    {
        self::prepare();
        if ($paddingBytes > 0) {
            self::comment(str_repeat(' ', $paddingBytes));
        }
        self::comment('connected ' . gmdate('c'));
    }

    public static function comment(string $text): void
    {
        self::flush(': ' . str_replace(["\r", "\n"], ' ', $text) . "\n\n");
    }

    /**
     * @param string|array<mixed>|object $data
     */
    public static function event(
        string|array|object $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retryMs = null
    ): void {
        $lines = [];

        if ($id !== null && $id !== '') {
            $lines[] = 'id: ' . self::singleLine($id);
        }
        if ($event !== null && $event !== '') {
            $lines[] = 'event: ' . self::singleLine($event);
        }
        if ($retryMs !== null && $retryMs >= 0) {
            $lines[] = 'retry: ' . $retryMs;
        }

        $payload = self::encodeData($data);
        foreach (preg_split("/\r\n|\n|\r/", $payload) ?: [''] as $line) {
            $lines[] = 'data: ' . $line;
        }

        self::flush(implode("\n", $lines) . "\n\n");
    }

    /**
     * @param string|array<mixed>|object $data
     */
    public static function data(string|array|object $data): void
    {
        self::event($data);
    }

    public static function done(): void
    {
        self::flush("data: [DONE]\n\n");
    }

    public static function ping(): void
    {
        self::comment('ping ' . gmdate('c'));
    }

    /**
     * @param callable(): (bool|null) $tick 返回 false 结束循环
     */
    public static function whileConnected(callable $tick, float $idlePingSeconds = 0): void
    {
        $lastPing = microtime(true);

        while (self::connected()) {
            if ($tick() === false) {
                break;
            }

            if ($idlePingSeconds > 0 && (microtime(true) - $lastPing) >= $idlePingSeconds) {
                self::ping();
                $lastPing = microtime(true);
            }

            if (!self::connected()) {
                break;
            }
        }
    }

    /**
     * 上游 SSE 原样透传，避免二次解析丢包。
     *
     * @param callable(callable(string): void, callable(): void): mixed $streamer onChunk, onIdle
     */
    public static function passthrough(callable $streamer, bool $kickoff = true, int $paddingBytes = 2048): void
    {
        if ($kickoff) {
            self::kickoff($paddingBytes);
        } else {
            self::prepare();
        }

        $streamer(
            static function (string $chunk): void {
                self::flush($chunk);
            },
            static function (): void {
                self::ping();
            }
        );
    }

    /**
     * @param string|array<mixed>|object $data
     */
    private static function encodeData(string|array|object $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }

    private static function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}