<?php

declare(strict_types=1);

namespace App;

final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    /** Las rutas admiten parámetros: /users/{id}. */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): array
    {
        $pathFound = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathFound = true;
            if ($method !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request);
        }
        throw new ApiException($pathFound ? 405 : 404, $pathFound ? 'Método no permitido.' : 'Ruta no encontrada.');
    }
}
