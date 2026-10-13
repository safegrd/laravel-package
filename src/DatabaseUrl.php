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
        $driver = (string) ($c['driver'] ?? '');
        if (! empty($c['url'])) {
            $url = self::normalise((string) $c['url'], $name);
            $scheme = (string) parse_url($url, PHP_URL_SCHEME);

            return self::withQuery($url, self::tls($c, $scheme === 'postgres' ? 'pgsql' : $scheme));
        }
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

                return self::withQuery($url, self::tls($c, $driver));
            case 'sqlsrv':
                throw new SafeGrdException("Connection {$name} is SQL Server, which SafeGrd does not back up yet.");
        }
        throw new SafeGrdException("Connection {$name} uses the driver '{$driver}'. SafeGrd backs up mysql, mariadb, pgsql and sqlite.");
    }

    /**
     * The TLS settings of a connection, as the CLI's URL takes them. For
     * MySQL and MariaDB they are PDO options: Laravel's own config sets
     * MYSQL_ATTR_SSL_CA from the variable of that name, which managed
     * databases need. For PostgreSQL they are the connection's sslmode,
     * sslrootcert, sslcert and sslkey, which libpq's URL takes as they are.
     * Nothing set adds nothing: a database on 127.0.0.1 has no TLS.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, string>
     */
    private static function tls(array $c, string $driver): array
    {
        $q = [];
        if ($driver === 'pgsql') {
            foreach (['sslmode', 'sslrootcert', 'sslcert', 'sslkey'] as $k) {
                if (! empty($c[$k]) && is_scalar($c[$k])) {
                    $q[$k] = (string) $c[$k];
                }
            }

            return $q;
        }
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return $q;
        }
        $options = is_array($c['options'] ?? null) ? $c['options'] : [];
        $ca = $options[self::mysqlAttr('SSL_CA', 1009)] ?? null;
        if (is_string($ca) && $ca !== '') {
            $q['ssl-ca'] = $ca;
            // PDO checks the certificate unless told not to, and so does the
            // CLI with ssl-ca. Told not to, both still encrypt.
            if (($options[self::mysqlAttr('SSL_VERIFY_SERVER_CERT', 1014)] ?? true) === false) {
                $q['tls'] = 'skip-verify';
            }
        }

        return $q;
    }

    /** A PDO MySQL attribute's number, by its name in PHP 8.4's Pdo\Mysql or before. */
    private static function mysqlAttr(string $name, int $fallback): int
    {
        foreach (['Pdo\Mysql::ATTR_'.$name, 'PDO::MYSQL_ATTR_'.$name] as $const) {
            if (defined($const)) {
                return (int) constant($const);
            }
        }

        return $fallback;
    }

    /**
     * $url with $add in its query string. A key the URL sets already keeps
     * the URL's value, as Laravel lets the URL win.
     *
     * @param  array<string, string>  $add
     */
    private static function withQuery(string $url, array $add): string
    {
        $have = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $have);
        $add = array_diff_key($add, $have);
        if ($add === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($add, '', '&', PHP_QUERY_RFC3986);
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
