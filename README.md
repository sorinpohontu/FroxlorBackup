# Froxlor Backup

A PHP CLI backup tool for servers running the [Froxlor](https://froxlor.org/) hosting control panel.

Backs up customer vhosts, databases, mailboxes and logs — plus system config files and the Froxlor panel itself — then syncs to remote storage via rsync or S3.

## Features

- **Customer backups** — vhosts, MySQL databases (with grants), mailboxes, access logs
- **System backup** — config files from a customisable file list (Apache, Postfix, Dovecot, SSH, etc.)
- **Control panel backup** — Froxlor files and database
- **Remote sync** — rsync (SSH) and/or S3 via `s3cmd`, with configurable retention
- **Email report** — built-in SMTP mailer, HTML + plain-text, no external libraries
- **Lock file** — prevents simultaneous runs via `flock()`
- **Dry-run mode** — simulate a full run without touching any files

## Requirements

- PHP 7.4+
- `tar` — always available on Linux
- `mysqldump` — if database backup is enabled
- `rsync` + `ssh` — if rsync sync is enabled
- `s3cmd` — if S3 sync is enabled ([s3tools.org](http://s3tools.org/download))
- `7z` — only if `archive_method` is set to `'7z'` (`apt-get install p7zip-full`)

## Installation

```bash
# Clone or copy the script to your server
git clone https://github.com/yourorg/froxlor-backup.git /root/bin/jobs/

# Copy the example config and edit it
cp config.local.php.example config.local.php
nano config.local.php
```

`config.local.php` is gitignored — keep your secrets there.

## Configuration

Configuration is split into two files:

| File | Purpose |
|------|---------|
| `config.php` | Default values — do not edit |
| `config.local.php` | Your overrides — only set what you need |

Values are deep-merged with `array_replace_recursive()`. See `config.local.php.example` for all available options with descriptions.

### Minimal example

```php
<?php
return [
    'customers' => ['dir' => '/var/backups/clients'],
    'rsync' => [
        'enabled'  => true,
        'ssh_host' => 'u123456',  // SSH host alias from ~/.ssh/config
    ],
    'email' => [
        'enabled' => true,
        'smtp' => [
            'host'     => 'smtp.example.com',
            'user'     => 'notify@example.com',
            'password' => 'smtp-password',
        ],
        'from' => 'notify@example.com',
        'to'   => 'admin@example.com',
    ],
];
```

### Archive method

Set `archive_method` to `'tar'` (default, produces `.tar.gz`) or `'7z'` (produces `.7z`). Applies to all backup sections.

### Retention

| Setting | Behaviour |
|---------|-----------|
| `keep_local_days => 0` | Flat layout — one copy per customer/section, overwritten each run |
| `keep_local_days => N` | Dated subdirs (`YYYY-MM-DD`) — keep N days, auto-delete older ones |
| `clean_before_backup => true` | Delete and recreate the backup dir before each run (flat layout only) — removes stale files from deleted vhosts/databases |

### System file list

`system-file-list` lists paths and globs (one per line) to include in the system backup. Edit it to match your server. Default entries include Apache, Postfix, Dovecot, MySQL, SSH, fail2ban, cron, and root's home directory.

For server-specific additions, create a `system-file-list.local` alongside it. If present, its entries are automatically merged with the base list at runtime — no config change needed. `system-file-list.local` is gitignored.

## Usage

```bash
# Normal run
php backup.php

# Detailed output (per-file progress)
php backup.php --verbose

# Simulate — show what would be done without touching files
php backup.php --dry-run

# Dry-run + send the email report (for SMTP/template testing)
php backup.php --dry-run --test-email
```

## Cron setup

Locking is built-in — no need for `flock` wrappers in cron. With email enabled, the script sends its own report and `MAILTO` is not needed.

```
# Froxlor Backup — every day at 02:00
0 2 * * *  root  nice -10 php /root/bin/jobs/backup.php
```

## File layout

```
backup.php                  Main script — orchestrator
config.php                  Default config array
config.local.php            Your local overrides (gitignored)
config.local.php.example    Documents all available options
system-file-list            Paths/globs to include in system backup
system-file-list.local      Server-specific additions (gitignored, auto-merged if present)
lib/
  BackupHelpers.php         Utility functions: output, fs, archive, db, sync, SMTP
  BackupSteps.php           Step functions: backupCustomers, backupSystem, stepSync*, etc.
reference/                  Previous implementation (read-only reference)
```

## License

BSD-3-Clause — Copyright 2023–2026 [Frontline softworks](https://www.frontline.ro)
