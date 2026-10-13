# SafeGrd for Laravel

Backs up a Laravel app with [SafeGrd](https://safegrd.dev): its database, its `.env` and
`storage/app` in one backup, encrypted on your server, stored locked against deletion, and
test-restored on a schedule to prove it opens.

```bash
composer require safegrd/laravel
php artisan safegrd:install
```

`safegrd:install` downloads the SafeGrd CLI, prints a code to approve in the SafeGrd console,
adds the app and takes the first backup. From then on Laravel's scheduler backs it up and
test-restores it. The app shows up in the console under Hosts.

Laravel 10, 11, 12 and 13 on PHP 8.1 or later, on Linux or macOS (amd64 or arm64). The database is
MySQL, MariaDB, PostgreSQL or SQLite.

## What is in a backup

| Part | How |
| :--- | :--- |
| The database | MySQL and MariaDB with `mysqldump` (or `mariadb-dump`), which the host needs. PostgreSQL and SQLite need nothing installed |
| `.env` | Whole, with the `APP_KEY` your encrypted columns, cookies and signed URLs need |
| `storage/app` | Every file with its mode. A file that did not change since the last backup is not uploaded again |
| `database/migrations`, `database/schema` | A few kilobytes, so the drill can compare them with the migrations table |

Left out: `vendor`, `node_modules`, `storage/framework`, `storage/logs`, `bootstrap/cache` and the
rest of the code, which lives in git. In `storage/app`: `backup-temp`, `livewire-tmp`, and the
zip archives spatie/laravel-backup writes there.

With `FILESYSTEM_DISK=s3` the uploads are in a bucket, not in `storage/app`. The backup takes the
database and the `.env` and warns. Back the bucket up as a files surface:
[safegrd.dev/docs/surfaces/files](https://safegrd.dev/docs/surfaces/files).

## The drill

A drill decrypts the latest backup and reads it in memory. It passes when:

- the database dump is complete and every table has the rows the backup counted;
- every file matches its SHA-256;
- the `.env` holds an `APP_KEY` of the length Laravel's cipher takes;
- the migrations table has a row for each migration that had run.

The console shows each drill, and alerts you when a drill fails or a backup is overdue: by
webhook on every plan, by email on paid plans. The overdue alert is how you hear about a
scheduler that stopped running.

## Where the connection comes from

On every run the package hands the CLI the connection as Laravel resolved it:
`config('database.connections.<default>')`, with whatever your host's environment sets, so a
password kept outside `.env` works. It reaches the CLI in an environment variable, never on a
command line, and is not written to disk. `config('database.migrations')` names the migrations
table the drill checks.

TLS settings come along with it. For MySQL and MariaDB that is `MYSQL_ATTR_SSL_CA` in the
connection's `options` (Laravel's default config reads it from the variable of that name), which
managed databases such as DigitalOcean's and AWS RDS need; with `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`
set to `false` the connection is encrypted without checking the certificate. For PostgreSQL it is
`sslmode`, `sslrootcert`, `sslcert` and `sslkey`.

## Commands

| Command | What it does |
| :--- | :--- |
| `php artisan safegrd:install` | Install the CLI, sign in, add the app and take the first backup |
| `php artisan safegrd:run` | Back up when due and drill when due. The scheduler runs it every five minutes |
| `php artisan safegrd:backup` | Back up now |
| `php artisan safegrd:status` | The last backup, the next one due and the last drill (`--json` for JSON) |
| `php artisan safegrd:list` | The backups, with the ids `restore` takes (`--json` for JSON) |
| `php artisan safegrd:doctor` | Check the connection, the CLI, its home and the schedule, then run the CLI's own checks |
| `php artisan safegrd:upgrade` | Download the CLI release `SAFEGRD_VERSION` names and put it in place of this app's copy |
| `php artisan safegrd:cli -- <args>` | The CLI with this app's setup, for example `safegrd:cli -- find .env` |

`safegrd:install` takes:

- `--token=env:SAFEGRD_TOKEN` (or `file:/path`) to sign in with a personal access token from
  Tokens in the console, for a deploy script or CI. Without it, it prints a code to approve. With
  `--no-interaction` the token is required.
- `--key-custody=local` for a customer-managed key. The default is a SafeGrd-managed key: SafeGrd
  keeps it sealed and releases it only to your enrolled hosts, so you can restore even after
  losing this server. With a customer-managed key only you can decrypt these backups; keep a copy
  of `storage/safegrd/.safegrd/keys/daemon.key` somewhere safe.
- `--storage=local` to keep the backups in a directory on this host instead of SafeGrd-hosted
  storage. A project with its own bucket uses that bucket either way.
- `--no-backup` to leave the first backup to the scheduler.

Run it as the user Laravel's scheduler runs as (the owner of `storage/`, such as `forge` or
`www-data`), so the scheduled runs can read what it sets up: `sudo -u forge php artisan safegrd:install`.
It warns when `storage/` belongs to another user. A `safegrd` already on `PATH` that is too old to
set up a Laravel app is left alone, and a newer one is downloaded for this app.

## The scheduler

The package registers `safegrd:run` with Laravel's scheduler, every five minutes, once
`safegrd:install` has run, in maintenance mode too. It exits at once when nothing is due, and logs
to `storage/logs/safegrd.log`, which is moved to `safegrd.log.1` once it passes 5 MB. The scheduler
needs its cron entry on the host:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Forge and Ploi each have a setting that adds it.

## When a backup fails

`safegrd:backup` and `safegrd:run` exit with the CLI's code: 10 when the database or the files could
not be read, 11 when the SafeGrd server could not be reached, 12 when the storage could not be
written, 1 for anything else. A CLI killed by a signal (the out-of-memory killer, say) or stopped by
`SAFEGRD_TIMEOUT` is a failure with its own message, never exit 0.

A failure is also written as an error to the app's log (`SAFEGRD_LOG_CHANNEL`, or the default
channel), so the error tracker the app reports to sees it, and dispatched as an event:

```php
use Illuminate\Support\Facades\Event;
use SafeGrd\Laravel\Events\BackupFailed;

Event::listen(function (BackupFailed $e) {
    // $e->command, $e->surface, $e->exitCode, $e->message, $e->output (the last lines)
});
```

The SafeGrd console alerts on the same failure, and on a backup that stops arriving at all.

`php artisan safegrd:doctor` checks the setup. The failures it finds most often:

- The home belongs to another user, because `safegrd:install` ran as root. Run it again as the
  app's user, or `chown -R` the home to that user.
- `storage` is on a filesystem mounted `noexec`, so the downloaded CLI cannot run. Install
  `safegrd` elsewhere and set `SAFEGRD_BINARY`.
- No cron entry for `schedule:run`. The console says the backup is overdue.

## Configuration

Every setting has a variable for `.env`. Publish the file to change them in code:
`php artisan vendor:publish --tag=safegrd-config`.

| Variable | Default | |
| :--- | :--- | :--- |
| `SAFEGRD_SCHEDULE` | `true` | `false` to run `safegrd:run` yourself |
| `SAFEGRD_SURFACE` | `APP_NAME` as a slug | The app's id in the console, chosen at install. Renaming the app later keeps the id |
| `SAFEGRD_CONNECTION` | the default connection | The connection to back up |
| `SAFEGRD_HOME` | `storage/safegrd` | The CLI's config, key and state. Outside `storage/app`, so the key is not in the backups it opens |
| `SAFEGRD_BINARY` | | A `safegrd` already on the host. Unset, one on `PATH` is used, or `safegrd:install` downloads one |
| `SAFEGRD_VERSION` | `latest` | The CLI release `safegrd:install` downloads, checked against its `checksums.txt` |
| `SAFEGRD_SERVER_URL` | `https://safegrd.dev` | The SafeGrd server `safegrd:install` enrolls with |
| `SAFEGRD_TIMEOUT` | | Seconds a run of the CLI may take before it is stopped. Unset, there is no limit |
| `SAFEGRD_LOG_CHANNEL` | the default channel | The log channel a failed backup is written to |

`storage/safegrd` holds the host's key and token. It gets a `.gitignore`, and is readable only by
the app's user. With Envoyer or Deployer the app is backed up through the `current` link, so the
backup follows each deploy, and `storage` is shared between releases, so the setup is too.

## Restoring

```bash
php artisan safegrd:list
php artisan safegrd:cli -- restore --snapshot <id> --target env:TARGET_URL --target-dir /tmp/app-restored
```

`TARGET_URL` names an empty database of the same engine. The `.env`, `storage/app` and the
migration files go into the empty directory. Then, in a checkout of the app:

1. Copy `.env` and `storage/app` from the restored directory into it.
2. Point `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` at the restored database.
3. Run `php artisan config:clear`, `php artisan storage:link` and `php artisan migrate:status`.

The full guide: [safegrd.dev/docs/surfaces/laravel](https://safegrd.dev/docs/surfaces/laravel).

## Not supported

Laravel Vapor and Laravel Cloud, which have no server for the CLI to run on, and SQL Server.
On a server you manage, the CLI can also run as a service without this package:
`safegrd init --laravel /var/www/app`, then `sudo safegrd daemon install`.

## License

MIT. The SafeGrd CLI it downloads is source-available under the Business Source License:
[github.com/safegrd/cli](https://github.com/safegrd/cli).
