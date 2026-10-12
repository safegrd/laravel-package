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
        ];
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('connections')]
    public function testBuildsTheUrlTheCliConnectsWith(array $c, string $want): void
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
    public function testRefusesWhatTheCliCannotBackUp(array $c, string $says): void
    {
        $this->expectException(SafeGrdException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($says, '/').'/');
        DatabaseUrl::fromConfig($c, 'default', '/srv/app');
    }

    public function testTheMigrationsTableIsAStringBeforeLaravel11AndAnArrayAfter(): void
    {
        $this->assertNull(DatabaseUrl::migrationsTable('migrations'));
        $this->assertNull(DatabaseUrl::migrationsTable(['table' => 'migrations', 'update_date_on_publish' => true]));
        $this->assertSame('schema_migrations', DatabaseUrl::migrationsTable('schema_migrations'));
        $this->assertSame('schema_migrations', DatabaseUrl::migrationsTable(['table' => 'schema_migrations']));
    }
}
