<?php

namespace SafeGrd\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use SafeGrd\Laravel\Cli;

final class CliConfigTest extends TestCase
{
    public function test_reads_the_laravel_surfaces_the_cli_writes(): void
    {
        // As gopkg.in/yaml.v3 marshals the CLI's config: four-space indent,
        // lists at the parent key's column plus four.
        $yaml = <<<'YAML'
            server:
                url: https://safegrd.dev
                token: sg_tok_x
            encryption:
                key_path: /srv/app/storage/safegrd/.safegrd/keys/daemon.key
            surfaces:
                - id: db
                  type: postgres
                  roots:
                    - /not/laravel
                - id: acme-shop
                  type: laravel
                  name: 'Acme: Shop'
                  roots:
                    - /srv/app/current
                  excludes:
                    - storage/app/tmp
                  database_url: env:SAFEGRD_LARAVEL_DATABASE_URL
                - id: "blog"
                  type: laravel
                  roots: [/srv/blog]
            daemon:
                interval: 5m
            YAML;
        $this->assertSame([
            ['id' => 'acme-shop', 'roots' => ['/srv/app/current']],
            ['id' => 'blog', 'roots' => ['/srv/blog']],
        ], Cli::laravelSurfaces($yaml));
    }

    public function test_a_config_with_no_surfaces_has_none(): void
    {
        $this->assertSame([], Cli::laravelSurfaces("server:\n    token: sg_tok_x\n"));
        $this->assertSame([], Cli::laravelSurfaces(''));
    }
}
