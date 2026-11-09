<?php
declare(strict_types=1);

/** Config accessor + tiny helper collection. */
final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        self::$data = require $file;
    }

    /** @return mixed */
    public static function get(string $key, $default = null)
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data;
    }
}

/** Any expected, user-facing failure. Status codes map to HTTP. */
class AppException extends RuntimeException
{
    public int $status;
    public string $errorCode;
    public array $meta;

    public function __construct(string $message, int $status = 400, string $errorCode = 'BAD_REQUEST', array $meta = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->meta = $meta;
    }
}

final class Response
{
    public static function ok($data = null, array $meta = [], int $status = 200): void
    {
        self::send($status, ['ok' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []));
    }

    public static function fail(string $message, int $status = 400, string $code = 'ERROR', array $meta = []): void
    {
        self::send($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + ($meta ? ['meta' => $meta] : [])]);
    }

    private static function send(int $status, array $payload): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        // A response is terminal: never let control fall through a switch case
        // and append a second JSON document to the body.
        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }
}

final class Input
{
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return self::$body = $json;
        }
        return self::$body = $_POST ?: [];
    }

    /** Required string field, trimmed, non-empty. */
    public static function need(array $src, string $key, int $max = 255): string
    {
        $v = isset($src[$key]) ? trim((string) $src[$key]) : '';
        if ($v === '') {
            throw new AppException("Field '{$key}' is required.", 422, 'VALIDATION');
        }
        if (mb_strlen($v) > $max) {
            throw new AppException("Field '{$key}' must be at most {$max} characters.", 422, 'VALIDATION');
        }
        return $v;
    }

    public static function str(array $src, string $key, string $default = '', int $max = 255): string
    {
        $v = isset($src[$key]) ? trim((string) $src[$key]) : $default;
        return mb_substr($v, 0, $max);
    }

    public static function num(array $src, string $key, float $default = 0.0): float
    {
        if (!isset($src[$key]) || $src[$key] === '' || $src[$key] === null) {
            return $default;
        }
        if (!is_numeric($src[$key])) {
            throw new AppException("Field '{$key}' must be a number.", 422, 'VALIDATION');
        }
        return (float) $src[$key];
    }

    public static function int(array $src, string $key, int $default = 0): int
    {
        return (int) round(self::num($src, $key, (float) $default));
    }

    public static function bool(array $src, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $src)) {
            return $default;
        }
        return in_array($src[$key], [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    public static function date(array $src, string $key, ?string $default = null): ?string
    {
        $v = self::str($src, $key);
        if ($v === '') {
            return $default;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            throw new AppException("Field '{$key}' must be a valid date.", 422, 'VALIDATION');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    public static function uuid(?string $value): string
    {
        $v = strtolower(trim((string) $value));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) {
            return $v;
        }
        return Db::uuid();
    }
}
