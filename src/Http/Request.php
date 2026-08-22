<?php

declare(strict_types=1);

namespace UpFix\Http;

/**
 * Thin wrapper over the PHP superglobals for the current request.
 */
final class Request
{
    /** @param array<string, mixed> $files */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $post,
        private readonly array $files,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return new self($method, $path, $_POST, $_FILES);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key): ?string
    {
        $value = $this->post[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** @return array<string, mixed>|null */
    public function files(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
}
