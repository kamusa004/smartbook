<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = Config::get('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) $c['port'], $c['name'], $c['charset']);
        try {
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            Response::fail(
                'Database connection failed. Check config/config.php and that MySQL is running.',
                500,
                'DB_CONNECTION',
                Config::get('app.debug') ? ['detail' => $e->getMessage()] : []
            );
            exit;
        }
        return self::$pdo;
    }

    /** Always use prepared statements - every query goes through here. */
    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return mixed */
    public static function val(string $sql, array $params = [], $default = null)
    {
        $v = self::q($sql, $params)->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    public static function id(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /** RFC-4122 v4 UUID - every offline record carries one so sync is idempotent. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Short human readable shop id, e.g. SB-4F7A21 */
    public static function shopUid(): string
    {
        do {
            $uid = 'SB-' . strtoupper(bin2hex(random_bytes(3)));
        } while (self::val('SELECT 1 FROM shops WHERE shop_uid = ?', [$uid]));
        return $uid;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
