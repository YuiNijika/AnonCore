<?php

declare(strict_types=1);

namespace Anon\Core\WebSocket;

final class Frame
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;

    /**
     * @return array{opcode: int, payload: string, fin: bool}|null
     */
    public static function decode(string &$buffer): ?array
    {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);
        $fin = ($b0 & 0x80) !== 0;
        $rsv = $b0 & 0x70;
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $payloadLen = $b1 & 0x7F;
        $offset = 2;

        if ($rsv !== 0) {
            throw new \Anon\Core\Exception\WebSocket('RSV bits require a negotiated extension.');
        }

        if (!in_array($opcode, [
            self::OPCODE_CONTINUATION,
            self::OPCODE_TEXT,
            self::OPCODE_BINARY,
            self::OPCODE_CLOSE,
            self::OPCODE_PING,
            self::OPCODE_PONG,
        ], true)) {
            throw new \Anon\Core\Exception\WebSocket('Unsupported WebSocket opcode.');
        }

        if (!$masked) {
            throw new \Anon\Core\Exception\WebSocket('Client WebSocket frames must be masked.');
        }

        if ($payloadLen === 126) {
            if ($len < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return null;
            }
            // 超 32bit 的帧本实现不支持，清空缓冲避免卡死
            $hi = unpack('N', substr($buffer, 2, 4))[1];
            $lo = unpack('N', substr($buffer, 6, 4))[1];
            if ($hi !== 0) {
                $buffer = '';

                return null;
            }
            $payloadLen = $lo;
            $offset = 10;
        }

        $maskLen = 4;
        if ($opcode >= self::OPCODE_CLOSE) {
            if (!$fin || $payloadLen > 125) {
                throw new \Anon\Core\Exception\WebSocket('Invalid WebSocket control frame.');
            }
        }

        $frameLen = $offset + $maskLen + $payloadLen;
        if ($len < $frameLen) {
            return null;
        }

        $mask = $masked ? substr($buffer, $offset, 4) : '';
        $payload = substr($buffer, $offset + $maskLen, $payloadLen);
        $buffer = substr($buffer, $frameLen);

        if ($masked && $mask !== '') {
            $decoded = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        return [
            'opcode'  => $opcode,
            'payload' => $payload,
            'fin'     => $fin,
        ];
    }

    /** RFC6455：服务端发出的帧不得掩码 */
    public static function encode(string $payload, int $opcode = self::OPCODE_TEXT, bool $fin = true): string
    {
        $frame = chr(($fin ? 0x80 : 0x00) | ($opcode & 0x0F));
        $len = strlen($payload);

        if ($len < 126) {
            $frame .= chr($len);
        } elseif ($len <= 0xFFFF) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('NN', 0, $len);
        }

        return $frame . $payload;
    }

    public static function close(int $code = 1000, string $reason = ''): string
    {
        return self::encode(pack('n', $code) . $reason, self::OPCODE_CLOSE);
    }

    public static function ping(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_PING);
    }

    public static function pong(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_PONG);
    }
}