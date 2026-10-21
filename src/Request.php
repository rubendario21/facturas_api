<?php

declare(strict_types=1);

namespace App;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $body,
        public readonly ?string $authorization,
        public readonly string $ip,
        public array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        [$path] = self::route();

        $body = [];
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                throw new ApiException(400, 'El cuerpo de la petición no es JSON válido.');
            }
        }

        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $auth = $value;
                }
            }
        }

        return new self($method, $path, $body, $auth, $_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * Ruta pedida y carpeta pública donde está instalada la API, p. ej.
     * ["/auth/login", "/facturas_api/public"] o ["/status", ""].
     */
    public static function route(): array
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Permite instalar la API en una subcarpeta (p. ej. /facturas_api/public).
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $path = '/' . trim($uri, '/');
        if (str_starts_with($path, '/index.php')) {
            $path = '/' . trim(substr($path, strlen('/index.php')), '/');
        }
        return [$path, $base];
    }

    public function bearerToken(): ?string
    {
        if ($this->authorization && preg_match('/^Bearer\s+(\S+)$/i', $this->authorization, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Campo de texto del cuerpo, recortado. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
