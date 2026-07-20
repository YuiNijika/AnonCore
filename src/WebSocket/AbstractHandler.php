<?php

declare(strict_types=1);

namespace Anon\Core\WebSocket;

abstract class AbstractHandler implements Handler
{
    public function onOpen(Connection $connection): void
    {
    }

    public function onMessage(Connection $connection, string $message, int $opcode): void
    {
    }

    public function onClose(Connection $connection): void
    {
    }
}