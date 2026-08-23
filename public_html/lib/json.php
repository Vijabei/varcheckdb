<?php
declare(strict_types=1);

/** Einheitliche JSON-Antworten fuer beide API-Fassungen. */
final class Json
{
    public static function send(mixed $data, int $status = 200, ?string $download = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');

        if ($download !== null) {
            header('Content-Disposition: attachment; filename="' . $download . '"');
        }

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        exit;
    }

    public static function error(string $message, int $status = 400): never
    {
        self::send(['error' => true, 'status' => $status, 'message' => $message], $status);
    }
}
