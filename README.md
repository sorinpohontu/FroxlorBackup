# Froxlor Backup

A PHP CLI backup tool for servers running the [Froxlor](https://froxlor.org/) hosting control panel.

Backs up customer vhosts, databases, mailboxes and logs — plus system config files and the Froxlor panel itself — then syncs to remote storage via rsync or S3.

## Features

- **Customer backups** — vhosts, MySQL databases (with grants), mailboxes, access logs
- **System backup** — config files from a customisable file list (Apache, Postfix, Dovecot, SSH, etc.)
- **Control panel backup** — Froxlor files and database
- **Remote sync** — rsync (SSH) and/or S3 via `s3cmd`, with configurable retention
- **Size tracking** — per-customer, per-step, and total backup size in output and summary
- **Email report** — built-in SMTP mailer, HTML + plain-text, no external libraries
- **Lock file** — prevents simultaneous runs via `flock()`
- **Dry-run mode** — simulate a full run without touching any files

## Requirements

- PHP 7.4+
- CLI PHP POSIX support and the `exec` function
- PHP `proc_open` and PDO MySQL when customer or control-panel backup is enabled
- PHP stream sockets when email reporting is enabled; OpenSSL for encrypted SMTP
- `tar` — when `archive_method` is set to `'tar'`
- `mysqldump` — if database backup is enabled
- `runuser` (util-linux) — when a root-run backup reads Froxlor-user-owned settings files
- `rsync` + `ssh` — if rsync sync is enabled
- `s3cmd` — if S3 sync is enabled ([s3tools.org](http://s3tools.org/download))
- `7zz` or `7z` — only if `archive_method` is set to `'7z'` (prefers official `7zz` via `apt install 7zip`, falls back to legacy `7z` via `apt install p7zip-full`)

`php backup.php --check-install` validates local PHP capabilities, files, configuration, writable paths, and binaries required by the enabled features. It is intentionally offline and does not test SSH/S3 connectivity or commands supported by a restricted remote endpoint.

## Installation

```bash
# Clone or copy the script to your server
git clone https://github.com/sorinpohontu/FroxlorBackup.git /opt/FroxlorBackup
chown -R root:root /opt/FroxlorBackup
chmod 0700 /opt/FroxlorBackup

# Copy the example config and edit it
cp config.local.php.example config.local.php
chmod 0600 config.local.php
nano config.local.php
```

`config.local.php` is gitignored — keep your secrets there.

For a root cron installation, make the backup application's files and `config.local.php` root-owned; use mode `0600` for the local config, `0640` or stricter for system file lists, and `0700` for the installation directory.

Froxlor's `lib/userdata.inc.php` and `lib/tables.inc.php` may retain [Froxlor's webserver/Froxlor-user ownership](https://docs.froxlor.org/v2/general/installation/tarball.html). These are PHP source files rather than inert data files. When the backup runs as root, it uses `runuser` to load them in an isolated PHP process as the owner of `userdata.inc.php`, then validates the returned settings and table names. The root backup process does not execute the Froxlor PHP files itself. `--check-install` checks that `runuser` and PHP `proc_open` are available; it does not execute Froxlor code or connect to its database.

## Releases

Push an annotated `vMAJOR.MINOR.PATCH` tag on a `main` commit after the release workflow is merged to request a GitHub release. The release workflow publishes only when at least one packaged file has changed since the previous version tag and the syntax and smoke checks pass on Debian 12 and 13. The first version tag always publishes. Tags must increase in version order and follow the same commit history; the workflow does not create or move tags. Protect `v*` tags in repository settings so only maintainers can request a release.

Download the `FroxlorBackup-vMAJOR.MINOR.PATCH.tar.gz` asset for installation. It contains `backup.php`, `config.php`, `lib/BackupHelpers.php`, `lib/BackupSteps.php`, `system-file-list`, `README.md`, `CHANGELOG.md`, and `LICENSE`. The accompanying `.sha256` file verifies the archive. GitHub's automatically generated “Source code” archives contain the full repository and are not the deployment package.

The release asset does not contain `config.local.php`, `config.local.php.example`, `system-file-list.local`, tests, or generated state. For a new installation, get the example config from the same Git tag or create your own `config.local.php`; preserve the server's local overrides when upgrading. After installing under `/opt/FroxlorBackup`, set the directory to root-owned mode `0700`, keep `config.local.php` at `0600`, and run `php backup.php --check-install` before a live backup.

## Upgrading from 1.x

- The default archive method is now `7z`. Install `7zz`/`7z`, or set `'archive_method' => 'tar'` in `config.local.php` to retain tar archives.
- Remote retention now defaults to `delete_strategy => 'after'`. An explicit `before` override remains supported with its pre-upload deletion risk.
- Custom `rsync.bin_params` and `s3.bin_params` settings were removed. Transport commands now use validated built-in arguments.
- Configuration, application files, backup roots, and remote paths are validated more strictly. Relative, overlapping, symlinked, or unsafe paths are rejected.
- Run `php backup.php --check-install` before the first 2.0 backup. See [CHANGELOG.md](CHANGELOG.md) for the complete release changes.

## Configuration

Configuration is split into two files:

| File | Purpose |
|------|---------|
| `config.php` | Default values — do not edit |
| `config.local.php` | Your overrides — only set what you need |

Values are deep-merged with `array_replace_recursive()`. See `config.local.php.example` for all available options with descriptions.

The default empty `timezone` detects the operating-system timezone, then falls back to the CLI PHP timezone. Set an explicit IANA name such as `Europe/Bucharest` in `config.local.php` only when the backup should use a different timezone.

### Minimal example

```php
<?php
return [
    'customers' => [
        'enabled' => true,
        'dir'     => '/var/backups/clients',
    ],
    'rsync'     => [
        'enabled'  => true,
        'ssh_host' => 'u123456',  // SSH host alias from ~/.ssh/config
    ],
    'email'     => [
        'enabled' => true,
        'smtp'    => [
            'host'     => 'smtp.example.com',
            'user'     => 'notify@example.com',
            'password' => 'smtp-password',
        ],
        'from'    => 'notify@example.com',
        'to'      => 'admin@example.com',
    ],
];
```

### Archive method

Set `archive_method` to `'7z'` (default, produces `.7z`) or `'tar'` (produces `.tar.gz`). Applies to all backup sections.

### Vhost file exclusions

`customers.vhosts.exclude_files` omits paths from every vhost document-root archive. Entries are relative paths, may name either a file, a directory, or an archive-native glob such as `system/storage/logs/*log`. A directory entry omits all of its descendants. It does not affect system, mail, database, GoAccess, or control-panel backups.

In combined-vhost mode, domain document roots inside the customer root contribute their relative prefixes to the exclusion list. A domain excluded with `exclude_domains` is omitted only when its document root is a distinct subdirectory; a shared root is rejected. Inspect archive members after changing combined-mode settings because tar and 7z use their own glob rules.

```php
'customers' => [
    'vhosts' => [
        'exclude_domains' => [
            'dev.example.com',
            'staging.example.com',
        ],
        'exclude_files'   => [
            'wp-content/cache/all',
            'system/storage/cache',
        ],
    ],
],
```

### Retention

| Setting | Behaviour |
|---------|-----------|
| `keep_local_days => 0` | Flat layout — one copy per customer/section, overwritten each run |
| `keep_local_days => N` | Dated subdirs (`YYYY-MM-DD`) — keep N days, auto-delete older ones |
| `clean_before_backup => true` | Prepare a new backup directory and replace the previous one only after the group succeeds (flat layout only) |

Remote retention defaults to `delete_strategy => 'after'`: old remote snapshots are pruned only after an upload succeeds. An explicit `before` setting still prunes before upload and can remove the last older usable snapshot if that upload fails. Remote sync skips an incomplete local backup group. Uploads mirror directly into the current date path, so a failed same-day rerun can leave that remote snapshot incomplete. A failed flat customer replacement leaves a hidden `.new-` directory for inspection; later customer sync stays blocked until it is resolved. A failed database backup leaves private SQL/grants files under `recovery/` for inspection; remove them after recovery.

### System file list

`system-file-list` lists paths and globs (one per line) to include in the system backup. Edit it to match your server. Default entries include Apache, Postfix, Dovecot, MySQL, SSH, fail2ban, cron, networking, package configuration, and selected system logs.

For server-specific additions, create a `system-file-list.local` alongside it. If present, both lists are read in memory at runtime — no config change needed. `system-file-list.local` is gitignored.

## Usage

```bash
# Show available options without loading configuration
php backup.php --help

# Normal run
php backup.php

# Detailed output (per-file progress)
php backup.php --verbose

# Simulate — show what would be done without touching files
php backup.php --dry-run

# Validate installation ownership, permissions, paths, and local dependencies
php backup.php --check-install

# Dry-run + send the email report (for SMTP/template testing)
php backup.php --dry-run --test-email
```

Plain dry-run sends no email and writes no lock or temporary list. `--test-email` is the explicit exception: it sends a test report. `--check-install` does not contact remote services or create files; it reports local prerequisites. At least one backup section must be enabled. Backups and restores still need target-server verification after installation.

## Cron setup

Locking is built-in — no need for `flock` wrappers in cron. With email enabled, the script sends its own report and `MAILTO` is not needed.

```
# Froxlor Backup — every day at 02:00 (low CPU + I/O priority)
0 2 * * *  root  nice -n 10 ionice -c2 -n7 php /opt/FroxlorBackup/backup.php
```

| Flag | Effect |
|------|--------|
| `nice -n 10` | Lower CPU priority (keeps the server responsive) |
| `ionice -c2 -n7` | Best-effort I/O class, lowest priority (reduces disk contention) |

## File layout

```
backup.php                  Main script — orchestrator
CHANGELOG.md                Release history and migration details
AGENTS.md                   Repository guidance for coding agents
config.php                  Default config array
config.local.php            Your local overrides (gitignored)
config.local.php.example    Documents all available options
system-file-list            Paths/globs to include in system backup
system-file-list.local      Server-specific additions (gitignored, auto-merged if present)
lib/
  BackupHelpers.php         Utility functions: output, fs, archive, db, sync, SMTP
  BackupSteps.php           Step functions: backupCustomers, backupSystem, stepSync*, etc.
tests/
  ReviewSmoke.php           Dependency-free regression checks
```

## License

BSD-3-Clause — Copyright 2023–2026 [Frontline softworks](https://www.frontline.ro)
