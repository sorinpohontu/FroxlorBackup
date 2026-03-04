# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
