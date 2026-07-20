<?php

declare(strict_types=1);

namespace Anon\Core\WebSocket;

use Anon\Core\Exception\WebSocket as WebSocketException;

/** 独立进程 RFC6455 服务，不走 PHP-FPM / php -S */
final class Server
{
    private string $host;

    private int $port;

    /** @var resource|null */
    private $serverSocket = null;

    private bool $running = false;

    /**
     * @var array<string, Handler|array{open?:callable,message?:callable,close?:callable}|callable>
     */
    private array $routes = [];

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var callable(string): void|null */
    private $logger = null;

    private int $maxConnections = 1024;

    private int $maxMessageBytes = 1024 * 1024;

    private int $maxBufferBytes = 2 * 1024 * 1024;

    /** 空闲达该秒数发 ping；0 关闭心跳 */
    private float $heartbeatSeconds = 30.0;

    /** 空闲断开秒数；0 时用 2 * heartbeat */
    private float $idleTimeoutSeconds = 0.0;

    /** @var list<string>|null null 表示不校验 Origin */
    private ?array $allowedOrigins = null;

    /** @var list<string> */
    private array $subprotocols = [];

    public function __construct(string $host = '0.0.0.0', int $port = 8081)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @param Handler|array{open?:callable,message?:callable,close?:callable}|callable $handler
     */
    public function route(string $path, Handler|array|callable $handler): self
    {
        $this->routes[$this->normalizePath($path)] = $handler;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->routes);
    }

    /**
     * @param callable(string): void $logger
     */
    public function logger(callable $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function maxConnections(int $n): self
    {
        $this->maxConnections = max(1, $n);

        return $this;
    }

    public function maxMessageBytes(int $bytes): self
    {
        $this->maxMessageBytes = max(1024, $bytes);

        return $this;
    }

    public function maxBufferBytes(int $bytes): self
    {
        $this->maxBufferBytes = max(1024, $bytes);

        return $this;
    }

    public function heartbeat(float $seconds): self
    {
        $this->heartbeatSeconds = max(0.0, $seconds);

        return $this;
    }

    public function idleTimeout(float $seconds): self
    {
        $this->idleTimeoutSeconds = max(0.0, $seconds);

        return $this;
    }

    /**
     * @param list<string>|null $origins null=不校验；[]=拒绝带 Origin 的请求
     */
    public function allowedOrigins(?array $origins): self
    {
        $this->allowedOrigins = $origins;

        return $this;
    }

    /**
     * @param list<string> $protocols
     */
    public function subprotocols(array $protocols): self
    {
        $this->subprotocols = array_values(array_filter(array_map('strval', $protocols)));

        return $this;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * @return list<Connection>
     */
    public function connections(): array
    {
        return array_values($this->connections);
    }

    public function find(string $id): ?Connection
    {
        foreach ($this->connections as $conn) {
            if ($conn->id() === $id) {
                return $conn;
            }
        }

        return null;
    }

    public function broadcast(string $message, ?string $path = null): void
    {
        $path = $path !== null ? $this->normalizePath($path) : null;
        foreach ($this->connections as $conn) {
            if ($path !== null && $conn->path() !== $path) {
                continue;
            }
            if ($conn->isOpen()) {
                $conn->send($message);
            }
        }
    }

    public function broadcastJson(mixed $data, ?string $path = null): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $this->broadcast($json, $path);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(): void
    {
        $addr = "tcp://{$this->host}:{$this->port}";
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($socket === false) {
            throw new WebSocketException("WebSocket bind failed on {$addr}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($socket, false);
        $this->serverSocket = $socket;
        $this->running = true;
        $this->installSignalHandlers();
        $this->log("WebSocket listening on ws://{$this->host}:{$this->port}");

        while ($this->running) {
            $read = [$socket];
            foreach ($this->connections as $conn) {
                if ($conn->isOpen()) {
                    $read[] = $conn->socket();
                }
            }

            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);
            if ($changed === false) {
                continue;
            }

            if ($changed > 0) {
                foreach ($read as $sock) {
                    if ($sock === $socket) {
                        $this->accept();
                        continue;
                    }

                    $id = (int) $sock;
                    $conn = $this->connections[$id] ?? null;
                    if ($conn === null) {
                        @fclose($sock);
                        continue;
                    }

                    $this->readFrom($conn);
                }
            }

            $this->tickHeartbeat();
        }

        foreach ($this->connections as $conn) {
            $conn->close(1001, 'server shutdown');
        }
        $this->connections = [];
        @fclose($socket);
        $this->serverSocket = null;
        $this->log('WebSocket server stopped');
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->log('signal received, shutting down…');
            $this->stop();
        };

        if (defined('SIGTERM')) {
            @pcntl_signal(SIGTERM, $stop);
        }
        if (defined('SIGINT')) {
            @pcntl_signal(SIGINT, $stop);
        }
    }

    private function tickHeartbeat(): void
    {
        if ($this->heartbeatSeconds <= 0 && $this->idleTimeoutSeconds <= 0) {
            return;
        }

        $now = microtime(true);
        $idleLimit = $this->idleTimeoutSeconds > 0
            ? $this->idleTimeoutSeconds
            : ($this->heartbeatSeconds > 0 ? $this->heartbeatSeconds * 2 : 0);

        foreach ($this->connections as $conn) {
            if (!$conn->isOpen()) {
                $this->drop($conn);
                continue;
            }

            $idle = $now - $conn->lastActiveAt();

            if ($idleLimit > 0 && $idle >= $idleLimit) {
                $this->log("idle timeout #{$conn->id()}");
                $conn->close(1001, 'idle timeout');
                $this->drop($conn, false);
                continue;
            }

            if ($this->heartbeatSeconds > 0 && $idle >= $this->heartbeatSeconds) {
                if (!$conn->ping()) {
                    $this->drop($conn);
                }
            }
        }
    }

    private function accept(): void
    {
        if ($this->serverSocket === null) {
            return;
        }

        $client = @stream_socket_accept($this->serverSocket, 0);
        if ($client === false) {
            return;
        }

        if (count($this->connections) >= $this->maxConnections) {
            $this->writeHttp($client, 503, 'Service Unavailable', 'Too many connections');
            @fclose($client);
            $this->log('reject: max connections');

            return;
        }

        stream_set_blocking($client, true);
        $raw = $this->readHttpHeaders($client);
        if ($raw === null) {
            @fclose($client);

            return;
        }

        $parsed = $this->parseHttpRequest($raw);
        if ($parsed === null || !$this->isWebSocketUpgrade($parsed['headers'])) {
            $this->writeHttp($client, 400, 'Bad Request', 'Expected WebSocket upgrade');
            @fclose($client);

            return;
        }

        if (!$this->checkOrigin($parsed['headers'])) {
            $this->writeHttp($client, 403, 'Forbidden', 'Origin not allowed');
            @fclose($client);

            return;
        }

        $path = $parsed['path'];
        $handler = $this->routes[$path] ?? null;
        if ($handler === null) {
            $this->writeHttp($client, 404, 'Not Found', "No WebSocket route for {$path}");
            @fclose($client);

            return;
        }

        $key = $parsed['headers']['sec-websocket-key'] ?? '';
        if ($key === '') {
            $this->writeHttp($client, 400, 'Bad Request', 'Missing Sec-WebSocket-Key');
            @fclose($client);

            return;
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $selectedProtocol = $this->selectSubprotocol($parsed['headers']);

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n";
        if ($selectedProtocol !== null) {
            $response .= "Sec-WebSocket-Protocol: {$selectedProtocol}\r\n";
        }
        $response .= "\r\n";

        @fwrite($client, $response);
        stream_set_blocking($client, false);

        $remote = (string) (@stream_socket_get_name($client, true) ?: '');
        $id = bin2hex(random_bytes(8));
        $conn = new Connection(
            $client,
            $id,
            $path,
            $parsed['headers'],
            $parsed['query'],
            $remote
        );
        if ($selectedProtocol !== null) {
            $conn->set('subprotocol', $selectedProtocol);
        }

        $this->connections[(int) $client] = $conn;
        $this->log("open #{$id} {$path}" . ($parsed['query'] !== '' ? "?{$parsed['query']}" : ''));
        $this->dispatchOpen($handler, $conn);
    }

    private function readFrom(Connection $conn): void
    {
        $socket = $conn->socket();
        $chunk = @fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            $this->drop($conn);

            return;
        }

        $conn->appendBuffer($chunk);
        if ($conn->bufferSize() > $this->maxBufferBytes) {
            $this->log("buffer overflow #{$conn->id()}");
            $conn->close(1009, 'message too big');
            $this->drop($conn, false);

            return;
        }

        $buffer = &$conn->bufferRef();

        while (true) {
            $frame = Frame::decode($buffer);
            if ($frame === null) {
                break;
            }

            $opcode = $frame['opcode'];
            $payload = $frame['payload'];
            $fin = $frame['fin'];

            if ($opcode === Frame::OPCODE_CLOSE) {
                $this->drop($conn, false);

                return;
            }

            if ($opcode === Frame::OPCODE_PING) {
                $conn->pong($payload);
                continue;
            }

            if ($opcode === Frame::OPCODE_PONG) {
                $conn->touch();
                continue;
            }

            if (
                $opcode !== Frame::OPCODE_TEXT
                && $opcode !== Frame::OPCODE_BINARY
                && $opcode !== Frame::OPCODE_CONTINUATION
            ) {
                continue;
            }

            if ($conn->fragmentBufferSize() + strlen($payload) > $this->maxMessageBytes) {
                $this->log("message too big #{$conn->id()}");
                $conn->clearFragments();
                $conn->close(1009, 'message too big');
                $this->drop($conn, false);

                return;
            }

            $message = $conn->assembleFragment($opcode, $payload, $fin);
            if ($message === null) {
                continue;
            }

            if (strlen($message['payload']) > $this->maxMessageBytes) {
                $conn->close(1009, 'message too big');
                $this->drop($conn, false);

                return;
            }

            $handler = $this->routes[$conn->path()] ?? null;
            if ($handler !== null) {
                $this->dispatchMessage($handler, $conn, $message['payload'], $message['opcode']);
            }
        }

        if (!$conn->isOpen()) {
            $this->drop($conn);
        }
    }

    private function drop(Connection $conn, bool $sendClose = true): void
    {
        $id = (int) $conn->socket();
        if (!isset($this->connections[$id])) {
            return;
        }

        $handler = $this->routes[$conn->path()] ?? null;
        if ($handler !== null) {
            $this->dispatchClose($handler, $conn);
        }

        if ($sendClose && $conn->isOpen()) {
            $conn->close();
        } else {
            $conn->markClosed();
            @fclose($conn->socket());
        }

        unset($this->connections[$id]);
        $this->log("close #{$conn->id()} {$conn->path()}");
    }

    /**
     * @param Handler|array<string, callable>|callable $handler
     */
    private function dispatchOpen(Handler|array|callable $handler, Connection $conn): void
    {
        try {
            if ($handler instanceof Handler) {
                $handler->onOpen($conn);

                return;
            }
            if (is_array($handler) && isset($handler['open']) && is_callable($handler['open'])) {
                ($handler['open'])($conn);
            }
        } catch (\Throwable $e) {
            $this->log('onOpen error: ' . $e->getMessage());
            $this->drop($conn);
        }
    }

    /**
     * @param Handler|array<string, callable>|callable $handler
     */
    private function dispatchMessage(Handler|array|callable $handler, Connection $conn, string $message, int $opcode): void
    {
        try {
            if ($handler instanceof Handler) {
                $handler->onMessage($conn, $message, $opcode);

                return;
            }
            if (is_callable($handler) && !is_array($handler)) {
                $handler($conn, $message, $opcode);

                return;
            }
            if (is_array($handler) && isset($handler['message']) && is_callable($handler['message'])) {
                ($handler['message'])($conn, $message, $opcode);
            }
        } catch (\Throwable $e) {
            $this->log('onMessage error: ' . $e->getMessage());
        }
    }

    /**
     * @param Handler|array<string, callable>|callable $handler
     */
    private function dispatchClose(Handler|array|callable $handler, Connection $conn): void
    {
        try {
            if ($handler instanceof Handler) {
                $handler->onClose($conn);

                return;
            }
            if (is_array($handler) && isset($handler['close']) && is_callable($handler['close'])) {
                ($handler['close'])($conn);
            }
        } catch (\Throwable $e) {
            $this->log('onClose error: ' . $e->getMessage());
        }
    }

    private function readHttpHeaders($socket): ?string
    {
        $data = '';
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $data .= $chunk;
            if (str_contains($data, "\r\n\r\n")) {
                return $data;
            }
            if (strlen($data) > 16384) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array{path: string, query: string, headers: array<string, string>}|null
     */
    private function parseHttpRequest(string $raw): ?array
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $lines = preg_split("/\r\n/", $headerBlock) ?: [];
        if ($lines === []) {
            return null;
        }

        $requestLine = array_shift($lines);
        if (!is_string($requestLine) || !preg_match('#^(GET)\s+(\S+)\s+HTTP/#i', $requestLine, $m)) {
            return null;
        }

        $uri = $m[2];
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $path = $this->normalizePath($path);

        $query = parse_url($uri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : '';

        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [
            'path'    => $path,
            'query'   => $query,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function isWebSocketUpgrade(array $headers): bool
    {
        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connection = strtolower($headers['connection'] ?? '');

        return $upgrade === 'websocket' && str_contains($connection, 'upgrade');
    }

    /**
     * @param array<string, string> $headers
     */
    private function checkOrigin(array $headers): bool
    {
        if ($this->allowedOrigins === null) {
            return true;
        }

        $origin = $headers['origin'] ?? '';
        if ($origin === '') {
            // 非浏览器客户端常无 Origin
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function selectSubprotocol(array $headers): ?string
    {
        if ($this->subprotocols === []) {
            return null;
        }

        $requested = $headers['sec-websocket-protocol'] ?? '';
        if ($requested === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $requested));
        foreach ($parts as $p) {
            if (in_array($p, $this->subprotocols, true)) {
                return $p;
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        return $path;
    }

    /**
     * @param resource $socket
     */
    private function writeHttp($socket, int $status, string $reason, string $body): void
    {
        $len = strlen($body);
        $msg = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Length: {$len}\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
        @fwrite($socket, $msg);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);

            return;
        }

        echo '[ws] ' . $message . PHP_EOL;
    }
}