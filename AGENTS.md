# AGENTS.md - Coding Agent Guide for Froxlor Backup

This document defines repository guidance for coding agents working on this project.

---

## Quick Reference

### Project Summary

**Froxlor Backup** is a PHP CLI backup tool for servers running the [Froxlor](https://froxlor.org/) hosting control panel. It backs up customer vhosts, databases, mailboxes, logs, system configs, and the control panel itself — then syncs to remote storage via rsync or S3.

### Key Technologies

| Technology | Version/Type |
|------------|-------------|
| PHP | 7.4+ |
| Database | MySQL/MariaDB (via PDO) |
| Archive | tar.gz or 7z (shell) |
| Sync | rsync, s3cmd |
| License | BSD-3-Clause |

### Essential Commands

```bash
# Show CLI options without loading configuration
php backup.php --help

# Validate local configuration and enabled dependencies without changing state
php backup.php --check-install

# Preview a run without writing files, taking the lock, or sending email
php backup.php --dry-run

# Run backup manually (console output)
php backup.php

# Run dependency-free regression checks
php tests/ReviewSmoke.php

# Check PHP syntax
php -l backup.php
php -l config.php
php -l config.local.php.example
php -l lib/BackupHelpers.php
php -l lib/BackupSteps.php
php -l tests/ReviewSmoke.php

# Check code style
phpcs --standard=ruleset.xml .

# Fix code style
phpcbf --standard=ruleset.xml .
```

> **Note:** `phpcs` and `phpcbf` are installed globally at `/usr/local/bin/` — use them directly, not via `./vendor/bin/`.

---

## Development Environment & Constraints

### Git Approval

Do not create commits or push branches or tags without the user's express approval
for that specific action. Approval of a plan, code change, or release version does
not imply approval to commit or push. Leave changes uncommitted and local tags
unpublished unless the user explicitly authorizes the next Git action.

### Remote Development Server

**IMPORTANT**: Source edits and static checks run locally, but real backup behavior must be tested on a disposable target or the remote development server.

| Action | Allowed | Notes |
|--------|---------|-------|
| Read/write code files | Yes | Full access to codebase |
| Run backup script | No | Use `--help` locally; real checks need the target configuration and services |
| Run smoke fixture | Yes | `php tests/ReviewSmoke.php` uses temporary local fixtures |
| Run phpcs/phpcbf | Yes | Code style checks work locally |
| Access databases | No | Databases are on remote server |
| Run composer/npm | No | The project has no Composer or npm dependency workflow |

### PHP 7.4 Compatibility

**IMPORTANT**: All code must be compatible with PHP 7.4+. Avoid features from later versions:

| Feature | Introduced | Alternative |
|---------|-----------|-------------|
| Enum types | PHP 8.1 | Use constants or validated strings |
| Named arguments | PHP 8.0 | Use positional arguments |
| Union types (`int\|string`) | PHP 8.0 | Use PHPDoc `@param int\|string` |
| Match expression | PHP 8.0 | Use `switch` statement |
| Nullsafe operator (`?->`) | PHP 8.0 | Use explicit null checks |
| `str_contains/str_starts_with/str_ends_with` | PHP 8.0 | Use `strpos`, `substr` |
| Arrow functions (`fn =>`) | PHP 7.4 | Allowed |
| Null coalescing assignment (`??=`) | PHP 7.4 | Allowed |

### Execution Context

The main execution contexts are:
- **Console** (manual): `php backup.php` — output goes to stdout
- **Cron**: output goes to stdout and is buffered internally; the completed report is sent through SMTP when enabled
- **Dry run**: `php backup.php --dry-run` — previews work without writing files, taking the lock, or sending the normal report
- **Installation check**: `php backup.php --check-install` — validates local configuration, paths, PHP capabilities, and enabled tools without contacting remote services
- **Help**: `php backup.php --help` — exits before loading configuration

`--dry-run --test-email` is the explicit exception to dry-run email suppression and sends a test report.

**Output capture**: All `output*()` functions write to both stdout and an internal buffer (`$_outputBuffer`). At the end of the script, `outputGet()` returns the full buffered output, which is sent via `smtpSend()` if email is enabled. This ensures the email contains the complete backup log regardless of execution mode.

---

## Project Structure

```
backup.php              ← Main entry point and orchestrator
config.php              ← Default config array
config.local.php        ← User overrides (not in repo)
config.local.php.example ← Documents all config options
system-file-list        ← List of system paths/globs to back up
lib/
  BackupHelpers.php     ← Utility functions: output, fs, archive, db, sync, SMTP, validation
  BackupSteps.php       ← Step functions: backupCustomers, backupSystem, stepSync*, etc.
tests/
  ReviewSmoke.php       ← Dependency-free regression fixture
ruleset.xml             ← PHPCS coding standard (PSR-12 based)
.editorconfig           ← Editor settings (4 spaces, LF, UTF-8)
```

---

## Coding Standards

### Key Rules

| Rule | Example |
|------|---------|
| Quotes | `'single'` for simple strings |
| Arrays | `[]` not `array()` |
| Indentation | 4 spaces |
| Functions | `camelCase()` with parameter and return types where PHP 7.4 permits |
| Concatenation | `'Hello' . ' World'` |

### Code Formatting Rules

| Rule | Requirement |
|------|-------------|
| Array alignment | Align `=>` operators vertically |
| Multi-line arrays | One element per line, trailing comma on last |
| Blank before return | Always leave blank line before `return` |
| Blank before comments | Always leave blank line before inline comments in functions |
| Comments | Keep code comments terse and factual; put rationale and narrative in `docs/` plans |
| Control structures | Use `{ }` braces, **never** `if: endif` or `foreach: endforeach` |
| PHPDoc spacing | Blank line after each `@tag` group in file headers |

**Correct Array Formatting:**

```php
// Short arrays - single line OK
$simple = ['one', 'two', 'three'];

// Associative arrays - align => operators
$config = [
    'archive_method'  => '7z',
    'keep_local_days' => 7,
];

// Nested arrays - maintain alignment per level
'rsync' => [
    'enabled'         => true,
    'ssh_host'        => 'backup-server',
    'delete_strategy' => 'after',
    'keep_days'       => 7,
],
```

**Correct Function Formatting:**
```php
function archiveExtension(string $method): string
{
    $extension = '.tar.gz';
    if ($method === '7z') {
        $extension = '.7z';
    }

    return $extension;
}
```

**Never Use Alternative Syntax:**
```php
// WRONG - Never use this
if ($dryRun):
    output('Preview only');
endif;

// CORRECT - Use braces
if ($dryRun) {
    output('Preview only');
}
```

### PHPDoc Headers

#### New vs existing files

**New files:** Generate the full header using `$CODE_AUTHOR`, `$CODE_COPYRIGHT`,
and `$CODE_LICENSE`. Set `@since` to today's date.

**Existing files:** Preserve the existing header. Update the copyright end-year
and set `@since` to today's date only when its current value is earlier.

Read values from shell environment variables:

```bash
echo "Author: $CODE_AUTHOR" && echo "Copyright: $CODE_COPYRIGHT" && echo "License: $CODE_LICENSE"
```

Template for new files:

```php
<?php
/**
 * @package     Froxlor Backup
 *
 * @subpackage  <Feature Name>
 *
 * @author      {$CODE_AUTHOR}
 * @copyright   {$CODE_COPYRIGHT}
 * @license     {$CODE_LICENSE}
 *
 * @since       <YYYY.MM.DD>
 */
```

### Function Documentation

```php
/**
 * Brief description
 *
 * @param string $source Source directory
 * @param string $dest   Destination path (without extension)
 * @param string $method Archive method ('tar' or '7z')
 *
 * @return bool True on success
 */
function archiveDirectory(string $source, string $dest, string $method): bool
```

---

## Architecture

### Config Pattern

- `config.php` returns a default array
- `config.local.php` (user-created) overrides specific values
- Merged via `array_replace_recursive()`
- The default empty timezone is resolved from the operating system, then the CLI PHP configuration
- The default archive method is `7z`; `tar` remains supported

### No Classes

All helpers are **plain functions** in `lib/BackupHelpers.php` — including database connectivity. No classes, no namespaces, no autoloading, no Composer.

### Step-Based Functions

`backup.php` is a flat orchestrator calling step functions:
- `backupCustomers()` — vhosts, databases, mails, logs per customer
- `backupSystem()` — system config files from file list
- `backupControlPanel()` — panel files + panel database
- `stepSyncRsync()` / `stepSyncS3()` — remote sync + retention

### Shared Helpers (DRY)

| Helper | Responsibility |
|--------|----------------|
| `archiveDirectory()` / `archivePathList()` | Shared tar/7z preparation, execution, validation, and publication |
| `dumpDatabase()` | Private SQL/grants creation, archiving, cleanup, and recovery preservation |
| `runCommandOutput()` | Checked command execution with bounded diagnostics |
| `findBinary()` | Executable lookup without command execution during config loading |
| `validateConfig()` | Configuration shape, path, trust-boundary, and conditional dependency checks |

Backup steps return completion results. Remote publication and after-upload retention must remain blocked when the corresponding local group or upload fails. Flat clean-before backups use a staged replacement directory so a failed run does not delete the previous published copy.

Froxlor's `userdata.inc.php` and `tables.inc.php` are executable PHP inputs. A root backup must not require webserver-owned Froxlor PHP directly. `loadFroxlorSettings()` invokes an isolated PHP process through `runuser` as the trusted Froxlor file owner, then validates the returned settings and table identifiers in the root process.

---

## Security Guidelines

| Rule | Requirement |
|------|-------------|
| Shell arguments | Validate semantic input and quote every shell argument; never restore user-supplied command options |
| Backup paths | Require absolute dedicated roots, reject unsafe overlap/symlink boundaries, and keep deletion contained |
| Sensitive output | Create SQL, grants, recovery data, config, and runtime state with restrictive permissions |
| Trusted PHP inputs | Validate type, ownership, permissions, and parent directories before loading |
| Config secrets | Keep in `config.local.php` (gitignored) |
| SMTP credentials | Keep in config, never hardcode |
| Remote retention | Select only validated immediate `YYYY-MM-DD` snapshot directories under the configured prefix |

---

## CLI Arguments

The script supports these optional flags:

| Flag | Description |
|------|-------------|
| `-h`, `--help` | Show usage without loading configuration |
| `--verbose` | Show detailed output (per-file progress, etc.) |
| `--dry-run` | Preview without changing files or sending the normal report |
| `--test-email` | Send a test report; valid only together with `--dry-run` |
| `--check-install` | Validate local requirements without changing state or contacting remote services |

```bash
php backup.php                # normal run
php backup.php --verbose      # detailed output
php backup.php --dry-run      # simulate only
php backup.php --check-install
php backup.php --dry-run --test-email
```

## Not in Scope

- No classes, namespaces, autoloading, or Composer architecture
- No parallel execution
- No third-party PHP runtime dependencies

---

## Version History

- **2026.03.04**: Initial coding-agent guide for the rewrite project
- **2026.09.24**: Updated architecture, safety contracts, commands, and validation guidance for 2.0.0

---

**License**: BSD-3-Clause | Copyright 2023-2026 Frontline softworks
