<?php

namespace SafeGrd\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SafeGrd\Laravel\DatabaseUrl;
use SafeGrd\Laravel\SafeGrdException;

final class DatabaseUrlTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string}> */
    public static function connections(): array
    {
        // Keyed by the constant, as Laravel's config/database.php does: the
        // numbers differ between PHP builds (PHP 8.5's are not 1009 and 1014).
        $ca = self::mysqlAttr('SSL_CA', 1009);
        $verify = self::mysqlAttr('SSL_VERIFY_SERVER_CERT', 1014);

        return [
            'mysql' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'shop', 'username' => 'app', 'password' => 'p@ss:w/rd'], 'mysql://app:p%40ss%3Aw%2Frd@127.0.0.1:3306/shop'],
            'mariadb' => [['driver' => 'mariadb', 'host' => 'db', 'database' => 'shop', 'username' => 'app', 'password' => ''], 'mariadb://app@db:3306/shop'],
            'pgsql with sslmode' => [['driver' => 'pgsql', 'host' => 'db', 'port' => 5433, 'database' => 'shop', 'username' => 'app', 'password' => 'x', 'sslmode' => 'require'], 'postgres://app:x@db:5433/shop?sslmode=require'],
            'read/write split' => [['driver' => 'mysql', 'read' => ['host' => ['replica']], 'write' => ['host' => ['primary']], 'database' => 'shop', 'username' => 'app', 'password' => 'x'], 'mysql://app:x@primary:3306/shop'],
            'ipv6' => [['driver' => 'pgsql', 'host' => '::1', 'database' => 'shop', 'username' => 'app', 'password' => 'x'], 'postgres://app:x@[::1]:5432/shop'],
            'sqlite absolute' => [['driver' => 'sqlite', 'database' => '/srv/app/database/database.sqlite'], 'sqlite:///srv/app/database/database.sqlite'],
            'sqlite relative' => [['driver' => 'sqlite', 'database' => 'database/database.sqlite'], 'sqlite:///srv/app/database/database.sqlite'],
            'url pgsql' => [['driver' => 'pgsql', 'url' => 'pgsql://app:x@db:5432/shop'], 'postgres://app:x@db:5432/shop'],
            'url mysql' => [['driver' => 'mysql', 'url' => 'mysql://app:x@db/shop'], 'mysql://app:x@db/shop'],
            'mysql with a CA' => [['driver' => 'mysql', 'host' => 'db.example', 'database' => 'shop', 'username' => 'app', 'password' => 'x', 'options' => [$ca => '/etc/ssl/do-ca.crt']], 'mysql://app:x@db.example:3306/shop?ssl-ca=%2Fetc%2Fssl%2Fdo-ca.crt'],
            'mysql with a CA, unverified' => [['driver' => 'mysql', 'host' => 'db', 'database' => 'shop', 'username' => 'app', 'password' => 'x', 'options' => [$ca => '/ca.pem', $verify => false]], 'mysql://app:x@db:3306/shop?ssl-ca=%2Fca.pem&tls=skip-verify'],
            'mysql with an empty CA, as Laravel\'s default config leaves it' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'shop', 'username' => 'app', 'password' => 'x', 'options' => []], 'mysql://app:x@127.0.0.1:3306/shop'],
            'pgsql with certificates' => [['driver' => 'pgsql', 'host' => 'db', 'database' => 'shop', 'username' => 'app', 'password' => 'x', 'sslmode' => 'verify-full', 'sslrootcert' => '/root.crt', 'sslcert' => '/c.crt', 'sslkey' => '/c.key'], 'postgres://app:x@db:5432/shop?sslmode=verify-full&sslrootcert=%2Froot.crt&sslcert=%2Fc.crt&sslkey=%2Fc.key'],
            'url pgsql takes the connection\'s sslmode' => [['driver' => 'pgsql', 'url' => 'postgres://app:x@db:5432/shop', 'sslmode' => 'require'], 'postgres://app:x@db:5432/shop?sslmode=require'],
            'url pgsql keeps its own sslmode' => [['driver' => 'pgsql', 'url' => 'postgres://app:x@db:5432/shop?sslmode=verify-full', 'sslmode' => 'prefer'], 'postgres://app:x@db:5432/shop?sslmode=verify-full'],
            'url mysql takes the CA' => [['driver' => 'mysql', 'url' => 'mysql://app:x@db/shop', 'options' => [$ca => '/ca.pem']], 'mysql://app:x@db/shop?ssl-ca=%2Fca.pem'],
        ];
    }

    private static function mysqlAttr(string $name, int $fallback): int
    {
        foreach (['Pdo\Mysql::ATTR_'.$name, 'PDO::MYSQL_ATTR_'.$name] as $const) {
            if (defined($const)) {
                return (int) constant($const);
            }
        }

        return $fallback;
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('connections')]
    public function test_builds_the_url_the_cli_connects_with(array $c, string $want): void
    {
        $this->assertSame($want, DatabaseUrl::fromConfig($c, 'default', '/srv/app'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'sqlsrv' => [['driver' => 'sqlsrv', 'host' => 'db', 'database' => 'x'], 'SQL Server'],
            'socket' => [['driver' => 'mysql', 'unix_socket' => '/run/mysqld/mysqld.sock', 'database' => 'x'], 'socket'],
            'memory' => [['driver' => 'sqlite', 'database' => ':memory:'], 'in-memory'],
            'no database' => [['driver' => 'mysql', 'host' => 'db', 'database' => ''], 'no database'],
            'other driver' => [['driver' => 'mongodb'], 'mongodb'],
        ];
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('refusals')]
    public function test_refuses_what_the_cli_cannot_back_up(array $c, string $says): void
    {
        $this->expectException(SafeGrdException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($says, '/').'/');
        DatabaseUrl::fromConfig($c, 'default', '/srv/app');
    }

    public function test_the_migrations_table_is_a_string_before_laravel11_and_an_array_after(): void
    {
        $this->assertNull(DatabaseUrl::migrationsTable('migrations'));
        $this->assertNull(DatabaseUrl::migrationsTable(['table' => 'migrations', 'update_date_on_publish' => true]));
        $this->assertSame('schema_migrations', DatabaseUrl::migrationsTable('schema_migrations'));
        $this->assertSame('schema_migrations', DatabaseUrl::migrationsTable(['table' => 'schema_migrations']));
    }
}
