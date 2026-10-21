<?php

declare(strict_types=1);

namespace App;

/** Tokens JWT firmados con HS256. */
final class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode($payload));
        $signature = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        return "$header.$body.$signature";
    }

    /** Devuelve el contenido si la firma es válida y no ha caducado; si no, null. */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $signature] = $parts;

        $head = json_decode(self::unb64($header), true);
        if (!is_array($head) || ($head['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $payload = json_decode(self::unb64($body), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
