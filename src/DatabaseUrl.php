<?php

namespace SafeGrd\Laravel;

/**
 * The URL the SafeGrd CLI connects with, built from a connection as Laravel
 * resolved it: config/database.php, its .env, and whatever the host's
 * environment set. The password in it goes to the CLI through the
 * environment, never on a command line.
 */
final class DatabaseUrl
{
    /**
     * @param  array<string, mixed>  $c  config('database.connections.<name>')
     */
    public static function fromConfig(array $c, string $name, string $basePath): string
    {
        if (! empty($c['url'])) {
            return self::normalise((string) $c['url'], $name);
        }
        $driver = (string) ($c['driver'] ?? '');
        switch ($driver) {
            case 'sqlite':
                $db = (string) ($c['database'] ?? '');
                if ($db === '' || $db === ':memory:') {
                    throw new SafeGrdException("Connection {$name} is an in-memory SQLite database, which holds nothing to back up.");
                }
                if ($db[0] !== '/') {
                    $db = rtrim($basePath, '/').'/'.$db;
                }

                return 'sqlite://'.$db;
            case 'mysql':
            case 'mariadb':
            case 'pgsql':
                if (! empty($c['unix_socket'])) {
                    throw new SafeGrdException("Connection {$name} connects through the socket {$c['unix_socket']}, and the SafeGrd CLI connects over TCP. Set DB_HOST and DB_PORT, or leave DB_SOCKET unset.");
                }
                $host = self::host($c);
                $port = (string) ($c['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
                $user = (string) ($c['username'] ?? '');
                $pass = (string) ($c['password'] ?? '');
                $db = (string) ($c['database'] ?? '');
                if ($host === '' || $db === '') {
                    throw new SafeGrdException("Connection {$name} names no host or no database.");
                }
                if (str_contains($host, ':') && $host[0] !== '[') {
                    $host = '['.$host.']';
                }
                $auth = rawurlencode($user).($pass !== '' ? ':'.rawurlencode($pass) : '');
                $scheme = $driver === 'pgsql' ? 'postgres' : $driver;
                $url = $scheme.'://'.($auth !== '' ? $auth.'@' : '').$host.':'.$port.'/'.rawurlencode($db);
                if ($driver === 'pgsql' && ! empty($c['sslmode'])) {
                    $url .= '?sslmode='.rawurlencode((string) $c['sslmode']);
                }

                return $url;
            case 'sqlsrv':
                throw new SafeGrdException("Connection {$name} is SQL Server, which SafeGrd does not back up yet.");
        }
        throw new SafeGrdException("Connection {$name} uses the driver '{$driver}'. SafeGrd backs up mysql, mariadb, pgsql and sqlite.");
    }

    /**
     * The migrations table config('database.migrations') names, or null for
     * Laravel's default. It is a string before Laravel 11 and an array after.
     */
    public static function migrationsTable(mixed $migrations): ?string
    {
        $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;
        if (! is_string($table) || $table === '' || $table === 'migrations') {
            return null;
        }

        return $table;
    }

    /** @param  array<string, mixed>  $c */
    private static function host(array $c): string
    {
        // A read/write split backs up from the write host, which has every row.
        $host = $c['write']['host'] ?? $c['host'] ?? '';
        if (is_array($host)) {
            $host = $host[0] ?? '';
        }

        return (string) $host;
    }

    private static function normalise(string $url, string $name): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $rest = substr($url, strlen($scheme));

        return match ($scheme) {
            'pgsql', 'postgres', 'postgresql' => 'postgres'.$rest,
            'mysql', 'mariadb', 'sqlite' => $scheme.$rest,
            default => throw new SafeGrdException("Connection {$name} has a url with the scheme '{$scheme}'. SafeGrd backs up mysql, mariadb, pgsql and sqlite."),
        };
    }
}
