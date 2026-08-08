<?php

declare(strict_types=1);

namespace Anon\Core\WebSocket;

final class Connection
{
    /** @var resource */
    private $socket;

    private string $id;

    private string $path;

    private string $query;

    private string $remote;

    /** @var array<string, string> */
    private array $headers;

    private string $buffer = '';

    private bool $open = true;

    private float $lastActiveAt;

    private string $fragmentPayload = '';

    private ?int $fragmentOpcode = null;

    /** @var array<string, mixed> */
    private array $attrs = [];

    /**
     * @param resource $socket
     * @param array<string, string> $headers
     */
    public function __construct(
        $socket,
        string $id,
        string $path,
        array $headers = [],
        string $query = '',
        string $remote = ''
    ) {
        $this->socket = $socket;
        $this->id = $id;
        $this->path = $path;
        $this->headers = $headers;
        $this->query = $query;
        $this->remote = $remote;
        $this->lastActiveAt = microtime(true);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(): string
    {
        return $this->query;
    }

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        if ($this->query === '') {
            return [];
        }

        $out = [];
        parse_str($this->query, $out);

        /** @var array<string, string> $normalized */
        $normalized = [];
        foreach ($out as $k => $v) {
            if (is_array($v)) {
                $normalized[(string) $k] = json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
            } else {
                $normalized[(string) $k] = (string) $v;
            }
        }

        return $normalized;
    }

    public function remoteAddress(): string
    {
        return $this->remote;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function touch(): void
    {
        $this->lastActiveAt = microtime(true);
    }

    public function lastActiveAt(): float
    {
        return $this->lastActiveAt;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attrs[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attrs[$key] ?? $default;
    }

    public function send(string $message, int $opcode = Frame::OPCODE_TEXT): bool
    {
        if (!$this->open) {
            return false;
        }

        return $this->writeRaw(Frame::encode($message, $opcode));
    }

    public function sendJson(mixed $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return $this->send($json);
    }

    public function sendBinary(string $bytes): bool
    {
        return $this->send($bytes, Frame::OPCODE_BINARY);
    }

    public function ping(string $payload = ''): bool
    {
        return $this->writeRaw(Frame::ping($payload));
    }

    public function pong(string $payload = ''): bool
    {
        return $this->writeRaw(Frame::pong($payload));
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->open) {
            return;
        }

        $this->writeRaw(Frame::close($code, $reason));
        $this->open = false;
        @fclose($this->socket);
    }

    /**
     * @return resource
     */
    public function socket()
    {
        return $this->socket;
    }

    public function appendBuffer(string $chunk): void
    {
        $this->buffer .= $chunk;
        $this->touch();
    }

    public function &bufferRef(): string
    {
        return $this->buffer;
    }

    public function bufferSize(): int
    {
        return strlen($this->buffer);
    }

    public function markClosed(): void
    {
        $this->open = false;
    }

    /**
     * 分片未收齐返回 null。
     *
     * @return array{opcode: int, payload: string}|null
     */
    public function assembleFragment(int $opcode, string $payload, bool $fin): ?array
    {
        if ($opcode === Frame::OPCODE_CONTINUATION) {
            if ($this->fragmentOpcode === null) {
                return null;
            }
            $this->fragmentPayload .= $payload;
            if (!$fin) {
                return null;
            }

            $complete = [
                'opcode'  => $this->fragmentOpcode,
                'payload' => $this->fragmentPayload,
            ];
            $this->clearFragments();

            return $complete;
        }

        if (!$fin) {
            $this->fragmentOpcode = $opcode;
            $this->fragmentPayload = $payload;

            return null;
        }

        $this->clearFragments();

        return [
            'opcode'  => $opcode,
            'payload' => $payload,
        ];
    }

    public function fragmentOpcode(): ?int
    {
        return $this->fragmentOpcode;
    }

    public function fragmentBufferSize(): int
    {
        return strlen($this->fragmentPayload);
    }

    public function clearFragments(): void
    {
        $this->fragmentOpcode = null;
        $this->fragmentPayload = '';
    }

    private function writeRaw(string $data): bool
    {
        if (!$this->open) {
            return false;
        }

        $total = strlen($data);
        $offset = 0;

        while ($offset < $total) {
            $written = @fwrite($this->socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                $this->open = false;

                return false;
            }
            $offset += $written;
        }

        $this->touch();

        return true;
    }
}