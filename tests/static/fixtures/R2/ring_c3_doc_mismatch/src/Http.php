<?php
declare(strict_types=1);
final class Http
{
    public static function json(array $data, int $code = 200): void { http_response_code($code); echo json_encode($data); }
    public static function error(int $code, string $message): void { self::json(['error' => $message], $code); exit; }
}
