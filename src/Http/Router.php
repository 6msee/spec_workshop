<?php

declare(strict_types=1);

namespace UpFix\Http;

/**
 * Small method+path table router. No regex/param extraction beyond exact
 * path matching this phase — the only route registered is a static path,
 * `POST /api/v1/tickets`.
 */
final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[strtoupper($method)][$pattern] = $handler;
    }

    public function dispatch(Request $request, Response $response, string $requestId): void
    {
        $method = $request->method();
        $path = rtrim($request->path(), '/') ?: '/';

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            $response->error(
                'TICKET_NOT_FOUND',
                'ไม่พบเส้นทางที่ร้องขอ',
                'The requested route was not found',
                404,
                $requestId,
            );

            return;
        }

        $handler($request, $response, $requestId);
    }
}
