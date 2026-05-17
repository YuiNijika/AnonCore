<?php

namespace Anon\Core\Auth;

use Anon\Core\Facade\Cache;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;
use Exception;

class JWTUtil
{
    protected static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    protected static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    public static function encode(array $payload, ?string $secret = null): string
    {
        $secret = $secret ?? (string) Config::get('auth.jwt_secret', Env::get('JWT_SECRET', 'anon_secret_key'));
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payloadJson = json_encode($payload);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode(string $jwt, ?string $secret = null): array
    {
        $secret = $secret ?? (string) Config::get('auth.jwt_secret', Env::get('JWT_SECRET', 'anon_secret_key'));
        $tokenParts = explode('.', $jwt);

        if (count($tokenParts) != 3) {
            throw new Exception("Invalid JWT format");
        }

        $header = $tokenParts[0];
        $payload = $tokenParts[1];
        $signatureProvided = $tokenParts[2];

        $signature = hash_hmac('sha256', $header . "." . $payload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        if (!hash_equals($base64UrlSignature, $signatureProvided)) {
            throw new Exception("Invalid JWT signature");
        }

        $decodedPayload = self::base64UrlDecode($payload);
        if ($decodedPayload === false) {
            throw new Exception("Invalid base64 encoding in payload");
        }

        $payloadData = json_decode($decodedPayload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payloadData)) {
            throw new Exception("Invalid JSON in payload");
        }

        $blacklistPrefix = (string) Config::get('auth.blacklist_prefix', 'auth:blacklist:');
        if (isset($payloadData['jti']) && Cache::has($blacklistPrefix . $payloadData['jti'])) {
            throw new Exception("JWT has been revoked");
        }

        if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
            throw new Exception("JWT has expired");
        }

        return $payloadData;
    }
}
