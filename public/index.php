<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ramsey\Uuid\Uuid;
use UpFix\Db\Connection;
use UpFix\Http\Controllers\TicketController;
use UpFix\Http\Request;
use UpFix\Http\Response;
use UpFix\Http\Router;
use UpFix\Support\Env;
use UpFix\Support\Logger;

Env::load(__DIR__ . '/..');

$requestId = 'req_' . Uuid::uuid4()->toString();
$response = new Response();

set_exception_handler(static function (\Throwable $e) use ($requestId, $response): void {
    Logger::get('http')->error('Unhandled exception', [
        'request_id' => $requestId,
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    // Never leak the throwable's message or trace to the client (SPEC.md
    // 6.3) — only the code and the request id are returned.
    $response->error(
        'INTERNAL_ERROR',
        'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่อีกครั้ง',
        'An internal error occurred',
        500,
        $requestId,
    );
});

$connection = Connection::fromEnv();
$ticketController = new TicketController($connection);

$router = new Router();
$router->add('POST', '/api/v1/tickets', [$ticketController, 'create']);

$router->dispatch(Request::fromGlobals(), $response, $requestId);
