<?php

declare(strict_types=1);

namespace UpFix\Http;

/**
 * SPEC.md 6.3 standard success/error envelope.
 */
final class Response
{
    /** @param array<string, mixed> $body */
    public function json(array $body, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function error(
        string $code,
        string $messageTh,
        string $messageEn,
        int $status,
        string $requestId,
    ): void {
        $this->json([
            'error' => [
                'code' => $code,
                'message_th' => $messageTh,
                'message_en' => $messageEn,
                'request_id' => $requestId,
            ],
        ], $status);
    }
}
