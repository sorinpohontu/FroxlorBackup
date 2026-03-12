# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.0] - 2026-03-12

### Added
- `formatDuration()` helper — formats elapsed time in human-readable format (e.g., "5 min 23s", "1h 15 min 42s")
- `find7zBinary()` and `require7zBinary()` helpers — intelligent 7-Zip binary detection

### Changed
- Duration output in progress messages now uses human-readable format via `formatDuration()`
- 7-Zip support now prefers the official `7zz` binary over the legacy `p7zip` (`7z`), with automatic fallback
- Updated all install instructions: `apt install 7zip` (Debian 12+) or `apt install p7zip-full` (Debian 11)
- Simplified sync summary tracking

---

## [1.1.0] - 2026-03-05

### Added
- Backup size tracking — each step and customer reports archive size in the "Done" line
- Per-customer size in output: `Done. (4.12 sec, 245.3 MB)`
- Customers total size after the customer loop
- Per-step sizes in the timing breakdown: `Customers 8.35s 327.4 MB  |  System 0.84s 12.5 MB`
- Total backup size in the summary line: `completed in 17.12s (1.42 GB total)`
- `formatSize()` and `dirSize()` helper functions

---

## [1.0.0] - 2026-03-04

### Added
- Initial version with core backup functionality
- `--verbose` flag for detailed per-file progress output
- `--dry-run` flag to simulate a full run without touching files
- `--test-email` flag to send the email report during a dry run (for SMTP/template testing)
- `7z` archive method support alongside the default `tar.gz`
- `keep_local_days` dated subdirectory retention with auto-cleanup of older backups
- `clean_before_backup` option to remove stale files from deleted vhosts/databases (flat layout)
- `config.local.php.example` documenting all available config options
- `system-file-list.local` support — server-specific path additions auto-merged at runtime
- Lock file via `flock()` to prevent simultaneous runs
- Built-in SMTP mailer — HTML + plain-text email report, no external libraries
