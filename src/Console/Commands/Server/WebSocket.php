<?php

declare(strict_types=1);

namespace Anon\Core\Console\Commands\Server;

use Anon\Core\Console\Command;
use Anon\Core\Facade\Config;
use Anon\Core\Foundation\App;
use Anon\Core\WebSocket\Connection;
use Anon\Core\WebSocket\Server;

class WebSocket extends Command
{
    protected string $name = 'ws';
    protected string $description = 'Start the built-in WebSocket server';

    public function execute(array $args): int
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : (getcwd() ?: '.');
        new App($basePath);

        $defaultHost = (string) Config::get('websocket.host', Config::get('server.host', '0.0.0.0'));
        $defaultPort = (string) Config::get('websocket.port', '8081');

        $host = (string) $this->getOption($args, 'host', $defaultHost);
        $port = (int) $this->getOption($args, 'port', $defaultPort);

        $server = new Server($host, $port);
        $this->applyServerOptions($server);
        $server->logger(function (string $message): void {
            $this->info($message);
        });

        $routeFile = $basePath . DIRECTORY_SEPARATOR . 'websocket.php';
        if (is_file($routeFile)) {
            $result = require $routeFile;
            if ($result instanceof Server) {
                $server = $result;
                $this->applyServerOptions($server);
                $server->logger(function (string $message): void {
                    $this->info($message);
                });
            } elseif (is_callable($result)) {
                $result($server);
            }
        } else {
            $server->route('/echo', [
                'open' => function (Connection $c): void {
                    $c->sendJson([
                        'type'   => 'welcome',
                        'id'     => $c->id(),
                        'path'   => $c->path(),
                        'query'  => $c->queryParams(),
                        'remote' => $c->remoteAddress(),
                    ]);
                },
                'message' => function (Connection $c, string $message): void {
                    $c->send($message);
                },
            ]);
            $this->warning('No websocket.php found — registered demo route /echo');
        }

        $paths = $server->paths();
        $this->success('Anon WebSocket server starting');
        $this->info('--------------------------------------------------');
        $this->info(" Local:      ws://{$server->host()}:{$server->port()}");
        $this->info(' Routes:     ' . ($paths !== [] ? implode(', ', $paths) : '(none)'));
        $this->info(' Config:     websocket.* in anon.config.php');
        $this->info('--------------------------------------------------');
        $this->warning('Press Ctrl+C to stop the server.');

        try {
            $server->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }

    private function applyServerOptions(Server $server): void
    {
        $maxConn = Config::get('websocket.max_connections');
        if ($maxConn !== null) {
            $server->maxConnections((int) $maxConn);
        }

        $maxMsg = Config::get('websocket.max_message_bytes');
        if ($maxMsg !== null) {
            $server->maxMessageBytes((int) $maxMsg);
        }

        $heartbeat = Config::get('websocket.heartbeat');
        if ($heartbeat !== null) {
            $server->heartbeat((float) $heartbeat);
        }

        $idle = Config::get('websocket.idle_timeout');
        if ($idle !== null) {
            $server->idleTimeout((float) $idle);
        }

        $origins = Config::get('websocket.allowed_origins');
        if (is_array($origins)) {
            /** @var list<string> $origins */
            $server->allowedOrigins($origins);
        }

        $protocols = Config::get('websocket.subprotocols');
        if (is_array($protocols)) {
            /** @var list<string> $protocols */
            $server->subprotocols($protocols);
        }
    }
}