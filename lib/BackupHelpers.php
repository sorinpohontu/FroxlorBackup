<?php

/**
 * @package     Froxlor Backup
 *
 * @subpackage  Helpers
 *
 * @author      Sorin Pohontu <sorin@frontline.ro>
 * @copyright   2026 Frontline softworks <https://www.frontline.ro>
 * @license     https://opensource.org/licenses/BSD-3-Clause
 *
 * @since       2026.09.25
 */

// =============================================================================
// OUTPUT & ERROR TRACKING
// =============================================================================

// @var string Internal output buffer (written to by all output* functions)
$_outputBuffer = '';

// @var int Number of errors encountered during the backup run
$_errorCount = 0;

/**
 * Initialize output buffer and error counter
 *
 * @return void
 */
function outputInit(): void
{
    global $_outputBuffer, $_errorCount;
    $_outputBuffer = '';
    $_errorCount   = 0;
}

/**
 * Write a timestamped line to stdout and the internal buffer
 *
 * @param string $msg Message to output
 *
 * @return void
 */
function output(string $msg): void
{
    global $_outputBuffer;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    $_outputBuffer .= $line;
}

/**
 * Write each line of a multi-line string with its own timestamp
 *
 * @param string $msg Multi-line message to output
 *
 * @return void
 */
function outputLines(string $msg): void
{
    $lines = preg_split('/\r\n|\r|\n/', $msg);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        output($line);
    }
}

/**
 * Write a section header separator to stdout and the internal buffer
 *
 * @param string $title Section title
 *
 * @return void
 */
function outputSection(string $title): void
{
    global $_outputBuffer;
    $header = PHP_EOL . str_repeat('-', 60) . PHP_EOL
        . '  ' . strtoupper($title) . PHP_EOL
        . str_repeat('-', 60) . PHP_EOL;
    echo $header;
    $_outputBuffer .= $header;
}

/**
 * Write a plain separator line (no timestamp, no title) to stdout and the internal buffer
 *
 * @return void
 */
function outputSeparator(): void
{
    global $_outputBuffer;
    $line = str_repeat('-', 60) . PHP_EOL;
    echo $line;
    $_outputBuffer .= $line;
}

/**
 * Output elapsed time and optional size since $startTime
 *
 * @param float   $startTime Result of microtime(true) at step start
 * @param integer $bytes     Backup size in bytes (0 = omit size from output)
 *
 * @return void
 */
function outputDone(float $startTime, int $bytes = 0): void
{
    $elapsed = microtime(true) - $startTime;
    $msg     = 'Done. (' . formatDuration($elapsed);
    if ($bytes > 0) {
        $msg .= ', ' . formatSize($bytes);
    }
    output($msg . ')');
}

/**
 * Output an error and an optional next step; count it once
 *
 * @param string $msg  Error message
 * @param string $hint Suggested next step
 *
 * @return void
 */
function outputError(string $msg, string $hint = ''): void
{
    global $_errorCount;
    $_errorCount++;
    output('ERROR: ' . $msg);
    if ($hint !== '') {
        output('  Next step: ' . $hint);
    }
}

/**
 * Output a warning message
 *
 * @param string $msg Warning message
 *
 * @return void
 */
function outputWarn(string $msg): void
{
    output('WARNING: ' . $msg);
}

/**
 * Return the full buffered output (for use as email body)
 *
 * @return string
 */
function outputGet(): string
{
    global $_outputBuffer;

    return $_outputBuffer;
}

/**
 * Return true if any errors were recorded during this run
 *
 * @return boolean
 */
function outputHasErrors(): bool
{
    global $_errorCount;

    return $_errorCount > 0;
}

// =============================================================================
// LOCK
// =============================================================================

/**
 * Acquire an exclusive non-blocking file lock to prevent simultaneous runs
 *
 * @param string $lockFile Path to lock file
 *
 * @return resource|false File pointer on success, false if already locked
 */
function acquireLock(string $lockFile)
{
    if (is_link($lockFile)) {
        outputError('Lock file must not be a symlink: ' . $lockFile);
        return false;
    }

    $fp = fopen($lockFile, 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, (string) getmypid());
    fflush($fp);
    return $fp;
}

/**
 * Release the file lock
 *
 * @param resource $fp File pointer returned by acquireLock()
 *
 * @return void
 */
function releaseLock($fp): void
{
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Ensure a secure runtime directory
 *
 * @param string  $path   Runtime directory
 * @param boolean $create Whether a missing directory may be created
 *
 * @return boolean
 */
function ensureSecureRuntimeDirectory(string $path, bool $create = true): bool
{
    if (is_link($path)) {
        outputError(
            'Backup runtime directory is a symlink: ' . $path,
            'Use a real directory for the backup installation or recovery workspace.'
        );
        return false;
    }
    if (!is_dir($path)) {
        if (!$create) {
            outputError(
                'Backup runtime directory does not exist: ' . $path,
                'Check the installed backup path and restore the missing directory.'
            );
            return false;
        }
        if (!mkdir($path, 0700, true)) {
            outputError(
                'Cannot create backup runtime directory: ' . $path,
                'Check the parent directory permissions and available disk space.'
            );
            return false;
        }
    }
    clearstatcache(true, $path);
    if (!is_dir($path) || (fileperms($path) & 0077) !== 0) {
        outputError(
            'Backup runtime directory allows group or other access: ' . $path,
            'Restrict this backup directory to mode 0700.'
        );
        return false;
    }
    if (isRootProcess() && fileowner($path) !== 0) {
        outputError(
            'Backup runtime directory must be root-owned when running as root: ' . $path,
            'Check ownership of this backup directory.'
        );
        return false;
    }

    return true;
}

// =============================================================================
// FILESYSTEM
// =============================================================================

/**
 * Create directory recursively with 0755 permissions if it does not exist
 *
 * @param string $path Directory path
 *
 * @return void
 */
function ensureDir(string $path): void
{
    if ($path === '' || $path[0] !== '/') {
        throw new \RuntimeException('Backup directory must be absolute: ' . $path);
    }
    $current = '';
    foreach (explode('/', trim($path, '/')) as $component) {
        $current .= '/' . $component;
        if (is_link($current) || (is_dir($current) && (fileperms($current) & 0022) !== 0)) {
            throw new \RuntimeException('Unsafe backup directory component: ' . $current);
        }
    }
    if (!is_dir($path) && !@mkdir($path, 0755, true)) {
        throw new \RuntimeException('Cannot create safe backup directory: ' . $path);
    }
    if (!is_dir($path) || (fileperms($path) & 0022) !== 0) {
        throw new \RuntimeException('Backup directory is unavailable: ' . $path);
    }
}

/**
 * Check a database-derived single path component
 *
 * @param string $value Component to check
 *
 * @return boolean
 */
function isSafePathComponent(string $value): bool
{
    return $value !== '' && $value !== '.' && $value !== '..'
        && preg_match('/^[A-Za-z0-9_.@-]+$/D', $value) === 1;
}

/**
 * Reject dangerous backup roots before creation or deletion
 *
 * @param string $path Backup root
 *
 * @return boolean
 */
function isSafeBackupRoot(string $path): bool
{
    if (
        $path === '' || $path[0] !== '/' || $path === '/'
        || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
    ) {
        return false;
    }
    $parts = explode('/', trim($path, '/'));
    if (count($parts) < 3) {
        return false;
    }
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    $current = '';
    foreach ($parts as $part) {
        $current .= '/' . $part;
        if (is_link($current)) {
            return false;
        }
    }

    return true;
}

/**
 * Check that a backup destination is outside a source tree
 *
 * @param string $destination Backup destination
 * @param string $source      Source directory
 *
 * @return boolean
 */
function isDestinationOutsideSource(string $destination, string $source): bool
{
    $sourcePath = realpath($source);
    return $sourcePath !== false
        && strpos(rtrim($destination, '/') . '/', rtrim($sourcePath, '/') . '/') !== 0;
}

/**
 * Check a remote path made from fixed safe components
 *
 * @param string $path Remote base path
 *
 * @return boolean
 */
function isSafeRemotePath(string $path): bool
{
    if (
        $path === '' || trim($path, '/') === '' || substr($path, -1) === '/'
        || $path[0] === '-' || preg_match('~^[A-Za-z0-9_./-]+$~D', $path) !== 1
    ) {
        return false;
    }
    foreach (explode('/', $path) as $component) {
        if ($component === '.' || $component === '..') {
            return false;
        }
    }

    return strpos($path, '//') === false;
}

/**
 * Check an S3 base URL
 *
 * @param string $path S3 URL
 *
 * @return boolean
 */
function isSafeS3Path(string $path): bool
{
    return preg_match('~^s3://[A-Za-z0-9][A-Za-z0-9.-]*(?:/[A-Za-z0-9_./-]+)?$~D', $path) === 1
        && isSafeRemotePath(substr($path, 5));
}

/**
 * Check the first existing ancestor of a destination
 *
 * @param string $path Destination path
 *
 * @return boolean
 */
function isCreatableBackupPath(string $path): bool
{
    if (!isSafeBackupRoot($path)) {
        return false;
    }
    $current = '';
    foreach (explode('/', trim($path, '/')) as $component) {
        $current .= '/' . $component;
        if (is_dir($current) && (fileperms($current) & 0022) !== 0) {
            return false;
        }
    }
    while (!file_exists($path)) {
        $parent = dirname($path);
        if ($parent === $path) {
            return false;
        }
        $path = $parent;
    }

    return is_dir($path) && is_writable($path) && !is_link($path)
        && (fileperms($path) & 0022) === 0;
}

/**
 * Remove a previously checked backup directory
 *
 * @param string $path Directory to remove
 *
 * @return boolean
 */
function removeBackupDirectory(string $path): bool
{
    $parent = dirname($path);
    if (
        !isSafeBackupRoot($path) || is_link($path) || !is_dir($parent)
        || !is_writable($parent) || (fileperms($parent) & 0022) !== 0
    ) {
        outputError('Unsafe backup directory deletion: ' . $path);
        return false;
    }

    return runCommand('rm -rf -- ' . escapeshellarg($path), 'Backup directory deletion');
}

/**
 * Prepare an isolated replacement of a flat backup directory
 *
 * @param string $final Final directory
 *
 * @return string Temporary directory, or empty on failure
 */
function prepareBackupReplacement(string $final): string
{
    if (!isSafeBackupRoot($final)) {
        outputError('Unsafe backup destination: ' . $final);
        return '';
    }
    ensureDir(dirname($final));
    $stage = dirname($final) . '/.' . basename($final) . '.new-' . bin2hex(random_bytes(8));
    if (!mkdir($stage, 0700)) {
        outputError('Cannot prepare backup replacement: ' . $stage);
        return '';
    }

    return $stage;
}

/**
 * Publish a complete replacement and retain the old copy on failure
 *
 * @param string $stage Prepared directory
 * @param string $final Final directory
 *
 * @return boolean
 */
function publishBackupReplacement(string $stage, string $final): bool
{
    if (!isSafeBackupRoot($stage) || !isSafeBackupRoot($final) || dirname($stage) !== dirname($final)) {
        outputError('Unsafe backup replacement');
        return false;
    }
    $previous = '';
    if (file_exists($final) || is_link($final)) {
        if (is_link($final) || !is_dir($final)) {
            outputError('Existing backup destination is unsafe: ' . $final);
            return false;
        }
        $previous = dirname($final) . '/.' . basename($final) . '.previous-' . bin2hex(random_bytes(8));
        if (!rename($final, $previous)) {
            outputError('Cannot preserve previous backup: ' . $final);
            return false;
        }
    }
    if (!rename($stage, $final)) {
        if ($previous !== '' && !rename($previous, $final)) {
            outputError('Cannot restore previous backup: ' . $previous);
        }
        outputError('Cannot publish backup: ' . $final);
        return false;
    }
    if ($previous !== '' && !removeBackupDirectory($previous)) {
        return false;
    }

    return true;
}

/**
 * Format a duration in seconds as a human-readable string
 *
 * @param float $seconds Duration in seconds
 *
 * @return string e.g. "23.45s", "5 min 12s", "1h 46 min 49s"
 */
function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return round($seconds, 2) . 's';
    }
    if ($seconds < 3600) {
        $min = floor($seconds / 60);
        $sec = (int) ($seconds - $min * 60);

        return $min . ' min ' . $sec . 's';
    }
    $hours = floor($seconds / 3600);
    $min   = floor(($seconds - $hours * 3600) / 60);
    $sec   = (int) ($seconds - $hours * 3600 - $min * 60);

    return $hours . 'h ' . $min . ' min ' . $sec . 's';
}

/**
 * Format a byte count as a human-readable string
 *
 * @param integer $bytes Byte count
 *
 * @return string e.g. "245.3 MB", "1.42 GB"
 */
function formatSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    return number_format($bytes / 1073741824, 2) . ' GB';
}

/**
 * Calculate total size of all files in a directory using du
 *
 * @param string $path Directory path
 *
 * @return integer|null Total size in bytes, or null on failure
 */
function dirSize(string $path): ?int
{
    if (!is_dir($path)) {
        outputError('Cannot measure missing backup directory: ' . $path);
        return null;
    }
    $lines = runCommandOutput('du -sb ' . escapeshellarg($path), 'Backup size', true);
    if ($lines === null || !isset($lines[0]) || preg_match('/^(\d+)\t/', $lines[0], $matches) !== 1) {
        outputError('Cannot read backup size: ' . $path);
        return null;
    }

    return (int) $matches[1];
}

/**
 * Find an executable in PATH, with result caching
 *
 * @param string $name Binary name (e.g. 'tar', '7z', 'rsync')
 *
 * @return string Full path, or empty string if not found
 */
function findBinary(string $name): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $cache[$name] = '';
        if (preg_match('/^[A-Za-z0-9._+-]+$/D', $name) !== 1) {
            return '';
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                $cache[$name] = $candidate;
                break;
            }
        }
    }

    return $cache[$name];
}

/**
 * Check whether a PHP function exists and is not disabled
 *
 * @param string $name Function name
 *
 * @return boolean
 */
function isPhpFunctionAvailable(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = ini_get('disable_functions');
    if (!is_string($disabled) || trim($disabled) === '') {
        return true;
    }
    foreach (explode(',', $disabled) as $function) {
        if (strcasecmp(trim($function), $name) === 0) {
            return false;
        }
    }

    return true;
}

/**
 * Find a binary and output an error if not found
 *
 * @param string $name Binary name
 *
 * @return string Full path, or empty string if not found
 */
function requireBinary(string $name): string
{
    $path = findBinary($name);
    if ($path === '') {
        outputError($name . ' not found. Install it first.');
    }

    return $path;
}

/**
 * Find the 7-Zip binary, preferring the official 7zz over the legacy p7zip 7z
 *
 * The official 7-Zip (7zz) supports modern CPU instructions (AES-NI, AVX2)
 * and better multithreading. Falls back to the legacy p7zip (7z) if 7zz
 * is not installed.
 *
 * @return string Full path, or empty string if neither is found
 */
function find7zBinary(): string
{
    // Try official 7-Zip first (Debian 12+: apt install 7zip)
    $path = findBinary('7zz');
    if ($path !== '') {
        return $path;
    }

    // Fall back to legacy p7zip (Debian 11: apt install p7zip-full)
    return findBinary('7z');
}

/**
 * Find the 7-Zip binary and output an error if not found
 *
 * @return string Full path, or empty string if not found
 */
function require7zBinary(): string
{
    $path = find7zBinary();
    if ($path === '') {
        outputError('7-Zip not found. Install 7zip (apt install 7zip) or p7zip-full (apt install p7zip-full).');
    }

    return $path;
}

// =============================================================================
// ARCHIVE
// =============================================================================

/**
 * Archive a directory using tar or 7z
 *
 * @param string   $source       Full path to source directory
 * @param string   $dest         Destination path without extension
 * @param string   $method       Archive method: 'tar' or '7z'
 * @param string[] $excludeFiles Relative exclusion paths
 *
 * @return boolean True if archive was created successfully
 */
function archiveDirectory(string $source, string $dest, string $method, array $excludeFiles = []): bool
{
    if (!is_dir($source)) {
        outputError('Directory not found: ' . $source);
        return false;
    }

    $excludeArgs = archiveExcludeArguments($excludeFiles, $method);

    if ($method === '7z') {
        $bin = require7zBinary();
        if ($bin === '') {
            return false;
        }
        $archiveFile = $dest . '.7z';
        $temporaryFile = archiveTemporaryPath($archiveFile);
        if ($temporaryFile === '') {
            return false;
        }

        $command = 'cd ' . escapeshellarg($source) . ' && '
            . escapeshellarg($bin) . ' a -t7z -mx=5 -bso0 -bsp0 '
            . escapeshellarg($temporaryFile) . ' '
            . escapeshellarg('./*')
            . $excludeArgs;

        return runArchiveCommand($command, $temporaryFile, $archiveFile);
    }

    $archiveFile = $dest . '.tar.gz';
    $temporaryFile = archiveTemporaryPath($archiveFile);
    if ($temporaryFile === '') {
        return false;
    }

    $command = 'tar -C ' . escapeshellarg($source)
        . ' -czf ' . escapeshellarg($temporaryFile)
        . $excludeArgs . ' .';

    return runArchiveCommand($command, $temporaryFile, $archiveFile);
}

/**
 * Build archive exclusion arguments
 *
 * @param string[] $excludeFiles Relative exclusion paths
 * @param string   $method       Archive method: 'tar' or '7z'
 *
 * @return string
 */
function archiveExcludeArguments(array $excludeFiles, string $method): string
{
    $arguments = '';

    foreach ($excludeFiles as $excludeFile) {
        if ($method === '7z') {
            $arguments .= ' ' . escapeshellarg('-xr!' . $excludeFile);
            continue;
        }

        $arguments .= ' --exclude=' . escapeshellarg($excludeFile);
    }

    return $arguments;
}

/**
 * Archive a list of files using tar or 7z
 *
 * @param string[] $files  Absolute paths to files to include
 * @param string   $dest   Destination path without extension
 * @param string   $method Archive method: 'tar' or '7z'
 *
 * @return boolean True if archive was created successfully
 */
function archiveFiles(array $files, string $dest, string $method): bool
{
    if (empty($files)) {
        outputError('No files available for archive: ' . $dest);
        return false;
    }

    if ($method === '7z') {
        $bin = require7zBinary();
        if ($bin === '') {
            return false;
        }
        $archiveFile = $dest . '.7z';
        $temporaryFile = archiveTemporaryPath($archiveFile);
        if ($temporaryFile === '') {
            return false;
        }

        $fileArgs = implode(' ', array_map('escapeshellarg', $files));
        $result = runArchiveCommand(
            escapeshellarg($bin) . ' a -t7z -mx=5 -bso0 -bsp0 '
            . escapeshellarg($temporaryFile) . ' ' . $fileArgs,
            $temporaryFile,
            $archiveFile
        );
    } else {
        $archiveFile = $dest . '.tar.gz';
        $temporaryFile = archiveTemporaryPath($archiveFile);
        if ($temporaryFile === '') {
            return false;
        }

        $dir      = escapeshellarg(dirname(reset($files)));
        $fileArgs    = implode(' ', array_map(fn($f) => escapeshellarg(basename($f)), $files));
        $result = runArchiveCommand(
            'tar -czf ' . escapeshellarg($temporaryFile) . ' -C ' . $dir . ' ' . $fileArgs,
            $temporaryFile,
            $archiveFile
        );
    }

    return $result;
}

/**
 * Archive files matching a glob pattern using tar or 7z
 *
 * Used for log backups where files match a pattern like "loginname*.log".
 *
 * @param string $pattern Shell glob pattern (e.g. 'web1*.log')
 * @param string $baseDir Directory to search in
 * @param string $dest    Destination path without extension
 * @param string $method  Archive method: 'tar' or '7z'
 *
 * @return boolean True if archive was created successfully
 */
function archiveGlob(string $pattern, string $baseDir, string $dest, string $method): bool
{
    $files = glob($baseDir . '/' . $pattern);
    if (empty($files)) {
        return false;
    }

    return archivePathList($files, $dest, $method, false);
}

/**
 * Archive files listed in a text file using tar or 7z
 *
 * Used for system config backup where paths/globs are listed one per line.
 *
 * @param string|string[] $fileListPath Paths to one or more file lists
 * @param string          $dest         Destination path without extension
 * @param string          $method       Archive method: 'tar' or '7z'
 *
 * @return boolean True if archive was created successfully
 */
function archiveFileList($fileListPath, string $dest, string $method): bool
{
    $files = archiveFileListEntries($fileListPath);
    if (empty($files)) {
        outputError('No files matched system file list');
        return false;
    }
    foreach ($files as $file) {
        if (is_dir($file) && !isDestinationOutsideSource(dirname($dest), $file)) {
            outputError('System source contains its backup destination: ' . $file);
            return false;
        }
    }

    return archivePathList($files, $dest, $method, true);
}

/**
 * Archive absolute paths with the requested 7z pathname mode
 *
 * @param string[] $files         Absolute source paths
 * @param string   $dest          Destination without extension
 * @param string   $method        Archive method
 * @param boolean  $preservePaths Preserve absolute paths in 7z archives
 *
 * @return boolean
 */
function archivePathList(array $files, string $dest, string $method, bool $preservePaths): bool
{
    $fileArgs = implode(' ', array_map('escapeshellarg', $files));
    $archiveFile = $dest . ($method === '7z' ? '.7z' : '.tar.gz');
    if ($method === '7z') {
        $bin = require7zBinary();
        if ($bin === '') {
            return false;
        }
    }
    $temporaryFile = archiveTemporaryPath($archiveFile);
    if ($temporaryFile === '') {
        return false;
    }
    if ($method === '7z') {
        $command = escapeshellarg($bin) . ' a -t7z -mx=5'
            . ($preservePaths ? ' -spf' : '') . ' -bso0 -bsp0 '
            . escapeshellarg($temporaryFile) . ' ' . $fileArgs;
    } else {
        $command = 'tar -czf ' . escapeshellarg($temporaryFile) . ' ' . $fileArgs;
    }

    return runArchiveCommand($command, $temporaryFile, $archiveFile);
}

/**
 * Resolve archive file-list entries
 *
 * @param string|string[] $fileListPath File list paths
 *
 * @return string[]
 */
function archiveFileListEntries($fileListPath): array
{
    $entries = [];
    foreach ((array) $fileListPath as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            outputError('Cannot read system file list: ' . $path);
            return [];
        }
        $entries = array_merge($entries, $lines);
    }

    $files = [];
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '' || strpos($entry, '#') === 0) {
            continue;
        }

        $matches = glob($entry, GLOB_NOSORT);
        if ($matches === false) {
            outputError('Invalid system file list pattern: ' . $entry);
            return [];
        }
        if (empty($matches)) {
            continue;
        }

        foreach ($matches as $match) {
            $files[$match] = $match;
        }
    }

    return array_values($files);
}

/**
 * Create an archive temporary path
 *
 * @param string $archiveFile Final archive path
 *
 * @return string
 */
function archiveTemporaryPath(string $archiveFile): string
{
    $temporaryFile = tempnam(dirname($archiveFile), '.' . basename($archiveFile) . '.');
    if ($temporaryFile === false) {
        outputError('Cannot create temporary archive: ' . $archiveFile);
        return '';
    }

    if (realpath(dirname($temporaryFile)) !== realpath(dirname($archiveFile))) {
        unlink($temporaryFile);
        outputError('Temporary archive is not beside destination: ' . $archiveFile);
        return '';
    }

    if (!unlink($temporaryFile)) {
        outputError('Cannot prepare temporary archive: ' . $temporaryFile);
        return '';
    }

    return $temporaryFile;
}

/**
 * Run and finalize an archive command
 *
 * @param string $command       Archive command
 * @param string $temporaryFile Temporary archive path
 * @param string $archiveFile   Final archive path
 *
 * @return boolean
 */
function runArchiveCommand(string $command, string $temporaryFile, string $archiveFile): bool
{
    if (!runCommand($command, 'Archive command', true)) {
        if (file_exists($temporaryFile)) {
            unlink($temporaryFile);
        }
        return false;
    }

    if (!is_file($temporaryFile) || filesize($temporaryFile) === 0) {
        outputError('Archive was not created: ' . $archiveFile);
        if (file_exists($temporaryFile)) {
            unlink($temporaryFile);
        }
        return false;
    }

    if (!rename($temporaryFile, $archiveFile)) {
        outputError('Cannot finalize archive: ' . $archiveFile);
        return false;
    }

    return true;
}

/**
 * Run a command and require a zero exit status
 *
 * @param string  $command     Command to run
 * @param string  $label       Error label
 * @param boolean $showDetails Show bounded command output on failure
 *
 * @return boolean
 */
function runCommand(string $command, string $label, bool $showDetails = false): bool
{
    return runCommandOutput($command, $label, $showDetails) !== null;
}

/**
 * Run a command and return its output
 *
 * @param string  $command     Command to run
 * @param string  $label       Error label
 * @param boolean $showDetails Show bounded command output on failure
 * @param string  $hint        Suggested next step on failure
 *
 * @return string[]|null
 */
function runCommandOutput(string $command, string $label, bool $showDetails = false, string $hint = ''): ?array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        outputError($label . ' failed (exit code ' . $exitCode . ')', $hint);
        if ($showDetails) {
            outputCommandDetails($output);
        }
        return null;
    }

    return $output;
}

/**
 * Report bounded command output
 *
 * @param string[] $lines Command output
 *
 * @return void
 */
function outputCommandDetails(array $lines): void
{
    foreach (array_slice($lines, -5) as $line) {
        $line = preg_replace('/[\x00-\x1f\x7f]/', ' ', $line);
        if ($line !== '') {
            output('  ' . substr($line, 0, 300));
        }
    }
}

/**
 * Quote an argument for the remote shell
 *
 * @param string $value Argument value
 *
 * @return string
 */
function remoteShellArg(string $value): string
{
    return '\'' . str_replace('\'', '\'"\'"\'', $value) . '\'';
}

/**
 * Run an SSH remote command
 *
 * @param string  $ssh         SSH binary path
 * @param string  $host        SSH host alias
 * @param string  $command     Remote command
 * @param string  $label       Error label
 * @param boolean $showDetails Show bounded command output on failure
 *
 * @return boolean
 */
function runRemoteCommand(string $ssh, string $host, string $command, string $label, bool $showDetails = false): bool
{
    return runCommand(escapeshellarg($ssh) . ' ' . escapeshellarg($host)
        . ' ' . escapeshellarg($command), $label, $showDetails);
}

// =============================================================================
// DATABASE
// =============================================================================

/**
 * Connect to a MySQL/MariaDB database via PDO
 *
 * @param string $host     Database host
 * @param string $database Database name
 * @param string $user     Database user
 * @param string $password Database password
 *
 * @return \PDO
 */
function dbConnect(string $host, string $database, string $user, string $password): \PDO
{
    $dsn = 'mysql:dbname=' . $database . ';host=' . $host;
    return new \PDO($dsn, $user, $password, [
        \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * Execute a SELECT query and return all rows as an associative array
 *
 * @param \PDO   $pdo PDO connection
 * @param string $sql SQL query
 *
 * @return array
 */
function dbQuery(\PDO $pdo, string $sql): array
{
    $stmt = $pdo->prepare(trim($sql));
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Execute a raw SQL statement and return all rows (e.g. for SHOW GRANTS)
 *
 * @param \PDO   $pdo PDO connection
 * @param string $sql SQL statement
 *
 * @return array
 */
function dbRunSQL(\PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);

    return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
}

/**
 * Dump a database and its grants to a compressed archive
 *
 * Runs mysqldump, writes grants, archives both, then removes the private inputs.
 *
 * @param string $dbName      Database name
 * @param string $destDir     Destination directory for the archive
 * @param \PDO   $dbRoot      Root PDO connection (for SHOW GRANTS)
 * @param string $dbUser      Database user (for grant lookup)
 * @param string $dbHost      Database host (for grant lookup)
 * @param string $sqlHost     MySQL host passed to mysqldump
 * @param string $method      Archive method: 'tar' or '7z'
 * @param string $archiveName Output archive base name (defaults to $dbName)
 *
 * @return boolean True when dump, grants and final archive succeed
 */
function dumpDatabase(
    string $dbName,
    string $destDir,
    \PDO $dbRoot,
    string $dbUser,
    string $dbHost,
    string $sqlHost,
    string $method,
    string $archiveName = ''
): bool {
    $archiveName = $archiveName !== '' ? $archiveName : $dbName;
    if (!isSafePathComponent($dbName) || !isSafePathComponent($archiveName)) {
        outputError('Invalid database archive name');
        return false;
    }

    $mysqldump = requireBinary('mysqldump');
    if ($mysqldump === '') {
        return false;
    }

    $recoveryRoot = dirname(__DIR__) . '/recovery';
    if (!ensureSecureRuntimeDirectory($recoveryRoot)) {
        return false;
    }
    $workspace = $recoveryRoot . '/dump-' . bin2hex(random_bytes(12));
    if (!mkdir($workspace, 0700)) {
        outputError('Cannot create database recovery workspace');
        return false;
    }
    $sqlFile = $workspace . '/' . $dbName . '.sql';
    $grantFile = $workspace . '/' . $dbName . '-grants.sql';
    $fp = fopen($sqlFile, 'x');
    if ($fp === false || !chmod($sqlFile, 0600)) {
        outputError('Cannot prepare private database dump: ' . $workspace);
        return false;
    }
    fclose($fp);

    $command = escapeshellarg($mysqldump) . ' --opt --allow-keywords'
        . ' -h ' . escapeshellarg($sqlHost)
        . ' ' . escapeshellarg($dbName)
        . ' -r ' . escapeshellarg($sqlFile);
    if (!runCommand($command, 'Database dump for ' . $dbName, true)) {
        outputError('Database recovery files kept at ' . $workspace);
        return false;
    }
    if (!is_file($sqlFile) || filesize($sqlFile) === 0) {
        outputError('Database dump is empty; recovery files kept at ' . $workspace);
        return false;
    }

    if (!dumpGrants($dbRoot, $dbUser, $dbHost, $grantFile)) {
        outputError('Database recovery files kept at ' . $workspace);
        return false;
    }

    if (!archiveFiles([$sqlFile, $grantFile], $destDir . '/' . $archiveName, $method)) {
        outputError('Database backup archive failed: ' . $dbName);
        outputError('Database recovery files kept at ' . $workspace);
        return false;
    }

    if (!unlink($sqlFile) || !unlink($grantFile)) {
        outputError('Cannot remove database sources; inspect ' . $workspace);
        return false;
    }

    if (!rmdir($workspace)) {
        outputError('Cannot remove empty database workspace: ' . $workspace);
        return false;
    }

    return true;
}

/**
 * Write a SHOW GRANTS result to a SQL file
 *
 * @param \PDO   $dbRoot   Root PDO connection
 * @param string $user     Database user
 * @param string $host     Database host
 * @param string $destFile Destination file path
 *
 * @return boolean
 */
function dumpGrants(\PDO $dbRoot, string $user, string $host, string $destFile): bool
{
    try {
        $grants = dbRunSQL($dbRoot, 'SHOW GRANTS FOR ' . $dbRoot->quote($user) . '@' . $dbRoot->quote($host));
    } catch (\Throwable $error) {
        outputError('Cannot read database grants: ' . $error->getMessage());
        return false;
    }
    if ($grants === []) {
        outputError('No database grants returned for backup');
        return false;
    }
    $fp = fopen($destFile, 'x');
    if (!$fp) {
        outputError('Cannot write grants file: ' . $destFile);
        return false;
    }
    if (!chmod($destFile, 0600)) {
        fclose($fp);
        outputError('Cannot restrict grants file: ' . $destFile);
        return false;
    }
    foreach ($grants as $grant) {
        foreach ($grant as $comment => $value) {
            if (!writeAllStream($fp, '# ' . $comment . "\n" . $value . ";\n")) {
                fclose($fp);
                outputError('Cannot write grants file: ' . $destFile);
                return false;
            }
        }
    }
    if (!fclose($fp)) {
        outputError('Cannot close grants file: ' . $destFile);
        return false;
    }

    return true;
}

// =============================================================================
// FROXLOR HELPERS
// =============================================================================

/**
 * Get a Froxlor panel setting value from the database
 *
 * @param \PDO   $db      Froxlor database connection
 * @param string $varname Setting variable name
 *
 * @return string|null Setting value, or null if not found
 */
function getSetting(\PDO $db, string $varname): ?string
{
    $rows = dbQuery($db, 'SELECT value FROM ' . TABLE_PANEL_SETTINGS . ' WHERE varname = \'' . $varname . '\'');

    return $rows ? $rows[0]['value'] : null;
}

// =============================================================================
// SYNC: RSYNC
// =============================================================================

/**
 * Sync a local directory to a remote host via rsync over SSH
 *
 * @param string $host      SSH host alias (from ~/.ssh/config)
 * @param string $localDir  Local source directory
 * @param string $remoteDir Remote destination directory
 * @return boolean True on success
 */
function doRsync(string $host, string $localDir, string $remoteDir): bool
{
    if (
        preg_match('/^[A-Za-z0-9_.@-]+$/D', $host) !== 1 || $host[0] === '-'
        || !isSafeRemotePath($remoteDir) || !is_dir($localDir)
    ) {
        outputError('Unsafe rsync source or destination');
        return false;
    }
    $ssh   = requireBinary('ssh');
    $rsync = requireBinary('rsync');
    if ($ssh === '' || $rsync === '') {
        return false;
    }
    if (!runRemoteCommand($ssh, $host, 'mkdir -p -- ' . remoteShellArg($remoteDir), 'Remote directory creation')) {
        return false;
    }

    return runCommand(
        escapeshellarg($rsync) . ' -ra --delete-after '
        . escapeshellarg($localDir . '/') . ' '
        . escapeshellarg($host . ':' . $remoteDir . '/'),
        'Rsync',
        true
    );
}

/**
 * List directories/files in a remote rsync path via SSH
 *
 * @param string $host      SSH host alias
 * @param string $remoteDir Remote directory path
 *
 * @return string[]|null List of entries, or null on failure
 */
function rsyncList(string $host, string $remoteDir): ?array
{
    if (
        preg_match('/^[A-Za-z0-9_.@-]+$/D', $host) !== 1 || $host[0] === '-'
        || !isSafeRemotePath($remoteDir)
    ) {
        outputError('Unsafe remote backup listing');
        return null;
    }
    $ssh = requireBinary('ssh');
    $rsync = requireBinary('rsync');
    if ($ssh === '' || $rsync === '') {
        return null;
    }
    if (!runRemoteCommand($ssh, $host, 'mkdir -p -- ' . remoteShellArg($remoteDir), 'Remote backup directory')) {
        return null;
    }
    $output = runCommandOutput(
        escapeshellarg($rsync) . ' --list-only -- '
        . escapeshellarg($host . ':' . $remoteDir . '/'),
        'Remote backup listing',
        true,
        'Check rsync access to the configured remote path.'
    );
    if ($output === null) {
        return null;
    }

    $directories = [];
    foreach ($output as $line) {
        if (preg_match('/\s(\S+)$/D', $line, $matches) !== 1) {
            continue;
        }
        $entry = rtrim($matches[1], '/');
        if (!isBackupDateDirectory($entry)) {
            continue;
        }
        if (preg_match('/^[-dlbcps][rwxstST-]{9}[+@.]?\s/', $line) !== 1) {
            outputError(
                'Cannot verify remote backup entry type: ' . $remoteDir . '/' . $entry,
                'Check the rsync --list-only output; retention was skipped.'
            );
            return null;
        }
        if ($line[0] === 'd') {
            $directories[] = $entry;
        }
    }

    return array_values(array_unique($directories));
}

/**
 * Delete files/directories on a remote host via SSH
 *
 * @param string   $host      SSH host alias
 * @param string   $remoteDir Remote base directory
 * @param string[] $files     Entries (relative to $remoteDir) to delete
 *
 * @return boolean
 */
function rsyncDeleteFiles(string $host, string $remoteDir, array $files): bool
{
    if (empty($files)) {
        return true;
    }
    if (
        preg_match('/^[A-Za-z0-9_.@-]+$/D', $host) !== 1 || $host[0] === '-'
        || !isSafeRemotePath($remoteDir)
    ) {
        outputError('Unsafe remote backup deletion');
        return false;
    }
    $ssh = requireBinary('ssh');
    if ($ssh === '') {
        return false;
    }
    foreach ($files as $file) {
        if (!isBackupDateDirectory($file)) {
            outputError('Refusing invalid remote backup directory: ' . $file);
            return false;
        }
        $deleted = runRemoteCommand(
            $ssh,
            $host,
            'rm -rf -- ' . remoteShellArg($remoteDir . '/' . $file),
            'Remote backup deletion',
            true
        );
        if (!$deleted) {
            return false;
        }
    }

    return true;
}

/**
 * Delete expired rsync backups
 *
 * @param string  $host      SSH host alias
 * @param string  $remoteDir Remote base directory
 * @param string  $date      Current date
 * @param integer $days      Retention days
 *
 * @return integer
 */
function rsyncDeleteExpired(string $host, string $remoteDir, string $date, int $days): ?int
{
    $files = rsyncList($host, $remoteDir);
    if ($files === null) {
        return null;
    }

    $expired = deletableFiles($date, $files, $days);

    return rsyncDeleteFiles($host, $remoteDir, $expired) ? count($expired) : null;
}

// =============================================================================
// SYNC: S3
// =============================================================================

/**
 * Sync a local directory to an S3 path using s3cmd
 *
 * @param string $localDir  Local source directory
 * @param string $remoteDir S3 destination path
 * @return boolean True on success
 */
function doS3Sync(string $localDir, string $remoteDir): bool
{
    if (!isSafeS3Path($remoteDir) || !is_dir($localDir)) {
        outputError('Unsafe S3 source or destination');
        return false;
    }
    $bin = requireBinary('s3cmd');
    if ($bin === '') {
        return false;
    }
    return runCommand(
        escapeshellarg($bin) . ' sync --delete-removed --quiet --no-guess-mime-type --human-readable-sizes '
        . escapeshellarg($localDir . '/') . ' '
        . escapeshellarg($remoteDir . '/'),
        'S3 sync'
    );
}

/**
 * List objects in an S3 path using s3cmd
 *
 * @param string $remoteDir S3 path
 *
 * @return string[]|null List of object keys, or null on failure
 */
function s3List(string $remoteDir): ?array
{
    if (!isSafeS3Path($remoteDir)) {
        outputError('Unsafe S3 backup listing');
        return null;
    }
    $bin = requireBinary('s3cmd');
    if ($bin === '') {
        return null;
    }
    $output = runCommandOutput(escapeshellarg($bin) . ' ls --recursive ' . escapeshellarg($remoteDir), 'S3 backup listing');
    if ($output === null) {
        return null;
    }

    $files = [];
    $prefix = rtrim($remoteDir, '/') . '/';
    foreach ($output as $line) {
        if (preg_match('/^\S+\s+\S+\s+\S+\s+(s3:\/\/.*)$/', $line, $matches)) {
            $file = $matches[1];
            if (strpos($file, $prefix) !== 0) {
                continue;
            }
            $relative = substr($file, strlen($prefix));
            $segments = explode('/', $relative);
            if (count($segments) > 1 && isBackupDateDirectory($segments[0])) {
                $files[] = $file;
            }
        }
    }

    return $files;
}

/**
 * Delete objects from S3 using s3cmd
 *
 * @param string   $remoteDir S3 base path
 * @param string   $date      Current date
 * @param integer  $days      Retention days
 * @param string[] $files     Full S3 object paths to delete
 *
 * @return boolean
 */
function s3DeleteFiles(string $remoteDir, string $date, int $days, array $files): bool
{
    if (empty($files)) {
        return true;
    }
    foreach ($files as $file) {
        if (!is_string($file) || !isSafeS3ExpiredObject($remoteDir, $date, $days, $file)) {
            outputError('Unsafe S3 backup deletion');
            return false;
        }
    }

    $bin = requireBinary('s3cmd');
    if ($bin === '') {
        return false;
    }
    foreach ($files as $file) {
        if (!runCommand(escapeshellarg($bin) . ' del ' . escapeshellarg($file), 'S3 backup deletion')) {
            return false;
        }
    }

    return true;
}

/**
 * Delete expired S3 backups
 *
 * @param string  $remoteDir S3 base path
 * @param string  $date      Current date
 * @param integer $days      Retention days
 *
 * @return integer
 */
function s3DeleteExpired(string $remoteDir, string $date, int $days): ?int
{
    $files = s3List($remoteDir);
    if ($files === null) {
        return null;
    }

    $expired = s3ExpiredObjects($remoteDir, $date, $days, $files);

    return s3DeleteFiles($remoteDir, $date, $days, $expired) ? count($expired) : null;
}

/**
 * Check an S3 object under an expired snapshot directory
 *
 * @param string  $remoteDir S3 base path
 * @param string  $date      Current date
 * @param integer $days      Retention days
 * @param string  $file      Full S3 object path
 *
 * @return boolean
 */
function isSafeS3ExpiredObject(string $remoteDir, string $date, int $days, string $file): bool
{
    if (!isSafeS3Path($remoteDir) || !isBackupDateDirectory($date) || $days < 1) {
        return false;
    }
    $prefix = rtrim($remoteDir, '/') . '/';
    if (strpos($file, $prefix) !== 0) {
        return false;
    }
    $parts = explode('/', substr($file, strlen($prefix)));
    if (
        count($parts) < 2 || !isBackupDateDirectory($parts[0])
        || strtotime($parts[0] . ' midnight') > strtotime($date . ' midnight -' . $days . ' days')
    ) {
        return false;
    }
    foreach ($parts as $part) {
        if (!isSafePathComponent($part)) {
            return false;
        }
    }

    return true;
}

/**
 * Select S3 keys by the immediate snapshot directory below a base URL
 *
 * @param string   $remoteDir S3 base URL
 * @param string   $date      Current date
 * @param integer  $days      Retention days
 * @param string[] $files     Listed object URLs
 *
 * @return string[] Expired object URLs
 */
function s3ExpiredObjects(string $remoteDir, string $date, int $days, array $files): array
{
    $expired = [];
    $prefix = rtrim($remoteDir, '/') . '/';
    $cutoff = strtotime($date . ' midnight -' . $days . ' days');
    foreach ($files as $file) {
        if (strpos($file, $prefix) !== 0) {
            continue;
        }
        $relative = substr($file, strlen($prefix));
        $snapshot = explode('/', $relative, 2)[0];
        if (isBackupDateDirectory($snapshot) && strtotime($snapshot . ' midnight') <= $cutoff) {
            $expired[] = $file;
        }
    }

    return $expired;
}

// =============================================================================
// RETENTION / CLEANUP
// =============================================================================

/**
 * Delete dated subdirectories (YYYY-MM-DD) inside $baseDir older than $days
 *
 * Used for local backup retention when keep_local_days > 0.
 * Only removes directories whose name matches the YYYY-MM-DD pattern.
 *
 * @param string  $baseDir Base directory containing dated subdirs
 * @param string  $today   Reference date (YYYY-MM-DD)
 * @param integer $days    Number of days to keep
 *
 * @return integer Number of directories removed
 */
function cleanLocalBackups(string $baseDir, string $today, int $days): ?int
{
    if (!is_dir($baseDir)) {
        return 0;
    }
    if (!isCreatableBackupPath($baseDir)) {
        outputError('Unsafe local retention root: ' . $baseDir);
        return null;
    }

    $removed         = 0;
    $cutoffTimestamp = strtotime($today . ' midnight -' . $days . ' days');
    $entries         = scandir($baseDir);
    if ($entries === false) {
        outputError('Cannot list local retention root: ' . $baseDir);
        return null;
    }

    foreach ($entries as $entry) {
        if (!isBackupDateDirectory($entry)) {
            continue;
        }
        $entryTimestamp = strtotime($entry . ' midnight');
        if ($entryTimestamp <= $cutoffTimestamp) {
            $path = $baseDir . '/' . $entry;
            if (is_link($path)) {
                outputError('Unsafe local retention entry: ' . $path);
                return null;
            }
            if (is_dir($path)) {
                if (!removeBackupDirectory($path)) {
                    return null;
                }
                $removed++;
            }
        }
    }

    return $removed;
}

/**
 * Prune dated snapshots below validated customer directories
 *
 * @param string  $baseDir Customer backup root
 * @param string  $today   Reference date
 * @param integer $days    Days to keep
 *
 * @return integer|null Number removed, or null on failure
 */
function cleanCustomerBackups(string $baseDir, string $today, int $days): ?int
{
    if (!isCreatableBackupPath($baseDir)) {
        outputError('Unsafe customer retention root: ' . $baseDir);
        return null;
    }
    if (!is_dir($baseDir)) {
        return 0;
    }
    $entries = scandir($baseDir);
    if ($entries === false) {
        outputError('Cannot list customer retention root: ' . $baseDir);
        return null;
    }
    $removed = 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $baseDir . '/' . $entry;
        if (!isSafePathComponent($entry) || is_link($path)) {
            outputError('Unsafe customer retention entry: ' . $entry);
            return null;
        }
        if (!is_dir($path)) {
            continue;
        }
        $count = cleanLocalBackups($path, $today, $days);
        if ($count === null) {
            return null;
        }
        $removed += $count;
    }

    return $removed;
}

/**
 * Return entries from $files whose embedded date is older than $days before $date
 *
 * Matches filenames/paths containing a YYYY-MM-DD date string.
 *
 * @param string   $date  Reference date string (YYYY-MM-DD), typically today
 * @param string[] $files List of file/directory names or paths
 * @param integer  $days  Number of days to keep
 *
 * @return string[] Entries eligible for deletion
 */
function deletableFiles(string $date, array $files, int $days): array
{
    $deletables       = [];
    $cutoffTimestamp  = strtotime($date . ' midnight -' . $days . ' days');

    foreach ($files as $file) {
        $entry = basename(rtrim($file, '/'));
        if (isBackupDateDirectory($entry)) {
            $fileTimestamp = strtotime($entry . ' midnight');
            if ($fileTimestamp <= $cutoffTimestamp) {
                $deletables[] = $file;
            }
        }
    }

    return $deletables;
}

/**
 * Check a backup date-directory name
 *
 * @param string $entry Directory name
 *
 * @return boolean
 */
function isBackupDateDirectory(string $entry): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry)) {
        return false;
    }

    $date = \DateTime::createFromFormat('!Y-m-d', $entry);

    return $date !== false && $date->format('Y-m-d') === $entry;
}

// =============================================================================
// SUMMARY
// =============================================================================

// @var array<string, int> Summary counters keyed by label
$_summary = [];

// @var array<string, float> Per-step elapsed times in seconds keyed by step label
$_stepTimes = [];

// @var array<string, int> Per-step sizes in bytes keyed by step label
$_stepSizes = [];

/**
 * Reset the summary counters and step timers
 *
 * @return void
 */
function summaryInit(): void
{
    global $_summary, $_stepTimes, $_stepSizes;
    $_summary   = [];
    $_stepTimes = [];
    $_stepSizes = [];
}

/**
 * Increment a summary counter
 *
 * @param string  $key   Label (e.g. 'customers', 'databases')
 * @param integer $count Amount to add (default 1)
 *
 * @return void
 */
function summaryAdd(string $key, int $count = 1): void
{
    global $_summary;
    $_summary[$key] = ($_summary[$key] ?? 0) + $count;
}

/**
 * Record elapsed time and optional size for a top-level step
 *
 * @param string  $label     Step label (e.g. 'Customers', 'System')
 * @param float   $stepStart Result of microtime(true) captured before the step ran
 * @param integer $bytes     Backup size in bytes (default 0)
 *
 * @return void
 */
function summaryTime(string $label, float $stepStart, int $bytes = 0): void
{
    global $_stepTimes, $_stepSizes;
    $_stepTimes[$label] = microtime(true) - $stepStart;
    $_stepSizes[$label] = $bytes;
}

/**
 * Return the summary counters as a human-readable string
 *
 * @return string e.g. "2 customers, 4 databases, 5 mailboxes"
 */
function summaryGet(): string
{
    global $_summary;
    $parts = [];
    foreach ($_summary as $key => $count) {
        $parts[] = $count . ' ' . $key;
    }

    return implode(' / ', $parts);
}

/**
 * Return per-step timing and sizes as a multi-line human-readable string
 *
 * @return string One line per step, e.g. "Customers   1h 5 min 29s   19.07 GB"
 */
function summaryTimingGet(): string
{
    global $_stepTimes, $_stepSizes;
    if (empty($_stepTimes)) {
        return '';
    }

    $durationStrings = [];
    $sizeStrings     = [];
    $maxLabelLen     = 0;
    $maxDurationLen  = 0;
    $maxSizeLen      = 0;

    foreach ($_stepTimes as $label => $elapsed) {
        $durationStrings[$label] = formatDuration($elapsed);
        $sizeStrings[$label]     = '';
        $maxLabelLen             = max($maxLabelLen, strlen($label));
        $maxDurationLen          = max($maxDurationLen, strlen($durationStrings[$label]));

        $size = $_stepSizes[$label] ?? 0;
        if ($size > 0) {
            $sizeStrings[$label] = formatSize($size);
            $maxSizeLen          = max($maxSizeLen, strlen($sizeStrings[$label]));
        }
    }

    $lines = [];
    foreach ($_stepTimes as $label => $elapsed) {
        $entry = str_pad($label, $maxLabelLen) . '   ' . str_pad($durationStrings[$label], $maxDurationLen);

        if ($sizeStrings[$label] !== '') {
            $entry .= '   ' . str_pad($sizeStrings[$label], $maxSizeLen, ' ', STR_PAD_LEFT);
        }

        $lines[] = rtrim($entry);
    }

    return implode(PHP_EOL, $lines);
}

/**
 * Return the total size across all steps in bytes
 *
 * @return integer Total size in bytes
 */
function summaryTotalSize(): int
{
    global $_stepSizes;
    $total = 0;
    foreach ($_stepSizes as $size) {
        $total += $size;
    }

    return $total;
}

// =============================================================================
// CONFIG VALIDATION
// =============================================================================

/**
 * Resolve an explicit or system timezone
 *
 * @param string      $configured Configured IANA timezone, or empty for automatic detection
 * @param string|null $source     Resolved source description
 *
 * @return string|null IANA timezone, or null when invalid
 */
function resolveTimezone(string $configured, ?string &$source = null): ?string
{
    $source = null;
    $timezones = timezone_identifiers_list();
    $configured = trim($configured);
    if ($configured !== '') {
        if (in_array($configured, $timezones, true)) {
            $source = 'configuration';

            return $configured;
        }

        return null;
    }

    if (is_file('/etc/timezone') && is_readable('/etc/timezone')) {
        $timezone = trim((string) file_get_contents('/etc/timezone'));
        if (in_array($timezone, $timezones, true)) {
            $source = '/etc/timezone';

            return $timezone;
        }
    }

    $localtime = realpath('/etc/localtime');
    if ($localtime !== false && preg_match('~/(?:zoneinfo|zoneinfo\.default)/(.+)$~D', $localtime, $matches) === 1) {
        if (in_array($matches[1], $timezones, true)) {
            $source = '/etc/localtime';

            return $matches[1];
        }
    }

    $phpTimezone = trim((string) ini_get('date.timezone'));
    if ($phpTimezone !== '' && in_array($phpTimezone, $timezones, true)) {
        $source = 'CLI PHP configuration';

        return $phpTimezone;
    }
    $phpTimezone = date_default_timezone_get();
    if (in_array($phpTimezone, $timezones, true)) {
        $source = 'CLI PHP default';

        return $phpTimezone;
    }

    return null;
}

/**
 * List external tools required by enabled features
 *
 * @param array $config Merged config array
 *
 * @return string[]
 */
function requiredToolLabels(array $config): array
{
    $customers = is_array($config['customers'] ?? null) ? $config['customers'] : [];
    $vhosts = is_array($customers['vhosts'] ?? null) ? $customers['vhosts'] : [];
    $system = is_array($config['system'] ?? null) ? $config['system'] : [];
    $panel = is_array($config['control_panel'] ?? null) ? $config['control_panel'] : [];
    $needsFroxlor = ($customers['enabled'] ?? false) === true || ($panel['enabled'] ?? false) === true;
    $needsArchive = (($customers['enabled'] ?? false) === true
        && (($vhosts['enabled'] ?? false) === true || ($customers['databases'] ?? false) === true
            || ($customers['logs'] ?? false) === true || ($customers['mails'] ?? false) === true))
        || ($system['enabled'] ?? false) === true || ($panel['enabled'] ?? false) === true;

    $tools = [];
    if ($needsArchive) {
        $method = $config['archive_method'] ?? null;
        $archive = $method === '7z' ? find7zBinary() : ($method === 'tar' ? findBinary('tar') : '');
        $tools[] = $archive !== '' ? basename($archive) : 'valid archiver';
    }
    if (
        (($customers['enabled'] ?? false) === true && ($customers['databases'] ?? false) === true)
        || ($panel['enabled'] ?? false) === true
    ) {
        $tools[] = 'mysqldump';
    }
    if ($needsFroxlor && isRootProcess() && is_string($panel['path'] ?? null)) {
        $owner = fileowner($panel['path'] . '/lib/userdata.inc.php');
        if ($owner !== false && $owner !== 0) {
            $tools[] = 'runuser';
        }
    }
    if (is_array($config['rsync'] ?? null) && ($config['rsync']['enabled'] ?? false) === true) {
        $tools[] = 'rsync';
        $tools[] = 'ssh';
    }
    if (is_array($config['s3'] ?? null) && ($config['s3']['enabled'] ?? false) === true) {
        $tools[] = 's3cmd';
    }

    return $tools;
}

/**
 * Describe successful installation checks
 *
 * @param array   $config    Merged config array
 * @param boolean $sendEmail Include test-email capabilities
 *
 * @return string[]
 */
function installationCheckLines(array $config, bool $sendEmail = false): array
{
    $customers = $config['customers'];
    $panel = $config['control_panel'];
    $needsFroxlor = $customers['enabled'] || $panel['enabled'];
    $emailEnabled = $config['email']['enabled'] || $sendEmail;

    $capabilities = ['POSIX', 'exec'];
    if ($needsFroxlor) {
        $capabilities[] = 'proc_open';
        $capabilities[] = 'PDO MySQL';
    }
    if ($emailEnabled) {
        $capabilities[] = 'stream sockets';
        if (($config['email']['smtp']['encryption'] ?? 'tls') !== '') {
            $capabilities[] = 'OpenSSL';
        }
    }

    $timezoneSource = null;
    $timezone = resolveTimezone($config['timezone'], $timezoneSource);

    $lines = [
        'PHP ' . PHP_VERSION . ' (required: 7.4+): ' . implode(', ', $capabilities),
        'Timezone: ' . $timezone . ' (' . $timezoneSource . ')',
        'Configuration and trusted backup inputs',
        'Backup and runtime paths',
    ];
    if ($needsFroxlor) {
        $userdata = $panel['path'] . '/lib/userdata.inc.php';
        $owner = fileowner($userdata);
        $account = $owner !== false ? posix_getpwuid($owner) : false;
        $ownerName = is_array($account) && isset($account['name']) ? $account['name'] : 'UID ' . $owner;
        if (isRootProcess() && $owner !== 0) {
            $lines[] = 'Froxlor settings access: ' . $ownerName . ' via runuser';
        } elseif ($owner === 0) {
            $lines[] = 'Froxlor settings access: root-owned trusted files';
        } else {
            $lines[] = 'Froxlor settings access: ' . $ownerName;
        }
    }
    $tools = requiredToolLabels($config);
    $lines[] = 'Enabled tools: ' . ($tools ? implode(', ', $tools) : 'none');

    return $lines;
}

/**
 * Validate the merged config array and output errors for any issues found
 *
 * @param array   $config    Merged config array
 * @param boolean $sendEmail Include test-email validation
 *
 * @return boolean True if config is valid
 */
function validateConfig(array $config, bool $sendEmail = false): bool
{
    $valid = true;
    $projectDir = dirname(__DIR__);
    if (PHP_VERSION_ID < 70400) {
        outputError('PHP 7.4 or newer is required');
        $valid = false;
    }
    if (!function_exists('posix_geteuid')) {
        outputError('POSIX effective-UID support is required');
        $valid = false;
    }
    if (!isPhpFunctionAvailable('exec')) {
        outputError(
            'CLI PHP cannot run required backup commands.',
            'Enable exec in the CLI PHP configuration and rerun --check-install.'
        );
        $valid = false;
    }

    foreach (['customers', 'system', 'control_panel', 'rsync', 's3', 'email'] as $section) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            outputError('Invalid configuration section: ' . $section);
            return false;
        }
        if (!isset($config[$section]['enabled']) || !is_bool($config[$section]['enabled'])) {
            outputError('Invalid enabled setting: ' . $section);
            return false;
        }
    }
    if (
        !isset($config['customers']['vhosts']) || !is_array($config['customers']['vhosts'])
        || !isset($config['email']['smtp']) || !is_array($config['email']['smtp'])
    ) {
        outputError('Invalid nested configuration section');
        return false;
    }
    if (!is_string($config['timezone'] ?? null) || resolveTimezone($config['timezone']) === null) {
        outputError('Invalid timezone. Use an IANA name or an empty value for system detection.');
        $valid = false;
    }
    if (!in_array($config['archive_method'] ?? null, ['tar', '7z'], true)) {
        outputError('archive_method must be tar or 7z');
        $valid = false;
    }
    if (!is_int($config['keep_local_days'] ?? null) || $config['keep_local_days'] < 0) {
        outputError('keep_local_days must be a non-negative integer');
        $valid = false;
    }
    if (!is_bool($config['clean_before_backup'] ?? null)) {
        outputError('clean_before_backup must be boolean');
        $valid = false;
    }
    foreach (['vhosts', 'databases', 'logs', 'mails'] as $key) {
        $value = $key === 'vhosts' ? ($config['customers']['vhosts']['enabled'] ?? null) : ($config['customers'][$key] ?? null);
        if (!is_bool($value)) {
            outputError('customers.' . $key . ' must be boolean');
            $valid = false;
        }
    }
    foreach (['separate_archives', 'goaccess'] as $key) {
        if (!is_bool($config['customers']['vhosts'][$key] ?? null)) {
            outputError('customers.vhosts.' . $key . ' must be boolean');
            $valid = false;
        }
    }
    if (!is_array($config['customers']['vhosts']['exclude_domains'] ?? null)) {
        outputError('customers.vhosts.exclude_domains must be an array');
        $valid = false;
    } else {
        foreach ($config['customers']['vhosts']['exclude_domains'] as $domain) {
            if (!is_string($domain) || preg_match('/^[A-Za-z0-9.-]+$/D', $domain) !== 1) {
                outputError('Invalid excluded domain');
                $valid = false;
            }
        }
    }
    foreach (['rsync', 's3'] as $name) {
        $sync = $config[$name];
        if (
            !in_array($sync['delete_strategy'] ?? null, ['before', 'after'], true)
            || !is_int($sync['keep_days'] ?? null) || $sync['keep_days'] < 1
        ) {
            outputError('Invalid ' . $name . ' retention settings');
            $valid = false;
        }
    }
    if (
        !is_string($config['customers']['dir'] ?? null) || !isSafeBackupRoot($config['customers']['dir'])
        || !is_string($config['system']['dir'] ?? null) || !isSafeBackupRoot($config['system']['dir'])
        || strpos(rtrim($config['system']['dir'], '/') . '/', rtrim($config['customers']['dir'], '/') . '/') === 0
        || strpos(rtrim($config['customers']['dir'], '/') . '/', rtrim($config['system']['dir'], '/') . '/') === 0
    ) {
        outputError('Backup roots must be separate safe absolute directories');
        $valid = false;
    }
    if (!$config['customers']['enabled'] && !$config['system']['enabled'] && !$config['control_panel']['enabled']) {
        outputError('No backup section is enabled');
        $valid = false;
    }
    if (
        $config['customers']['enabled'] && !$config['customers']['vhosts']['enabled']
        && !$config['customers']['databases'] && !$config['customers']['logs']
        && !$config['customers']['mails']
    ) {
        outputError('Customer backup has no enabled content');
        $valid = false;
    }

    foreach (glob($projectDir . '/*.php') as $file) {
        if (!validateTrustedFile($file, false)) {
            $valid = false;
        }
    }
    foreach (glob($projectDir . '/lib/*.php') as $file) {
        if (!validateTrustedFile($file, false)) {
            $valid = false;
        }
    }
    $localConfig = $projectDir . '/config.local.php';
    if (file_exists($localConfig) && !validateTrustedFile($localConfig, true)) {
        $valid = false;
    }

    $excludeFiles = $config['customers']['vhosts']['exclude_files'] ?? null;
    if (!is_array($excludeFiles)) {
        outputError('customers.vhosts.exclude_files must be an array');
        $valid = false;
    } else {
        foreach ($excludeFiles as $index => $excludeFile) {
            if (!is_string($excludeFile) || !isValidExcludePath($excludeFile)) {
                outputError('customers.vhosts.exclude_files contains an invalid path at index ' . $index);
                $valid = false;
            }
        }
    }

    // Backup directories writable (or creatable)
    if ($config['customers']['enabled']) {
        $dir = $config['customers']['dir'];
        if (!is_string($dir) || !isCreatableBackupPath($dir)) {
            outputError(
                'Customer backup directory is unsafe or not writable.',
                'Check customers.dir in config.local.php: use a dedicated absolute path with writable parents.'
            );
            $valid = false;
        }
    }

    if (
        ($config['system']['enabled'] || $config['control_panel']['enabled'])
        && (!is_string($config['system']['dir'] ?? null)
            || !isCreatableBackupPath($config['system']['dir']))
    ) {
        outputError(
            'System backup directory is unsafe or not writable.',
            'Check system.dir in config.local.php: use a dedicated absolute path with writable parents.'
        );
        $valid = false;
    }

    if ($config['system']['enabled']) {
        if (!is_string($config['system']['file_list'] ?? null) || $config['system']['file_list'] === '') {
            outputError('System file list not configured');
            $valid = false;
        } elseif (!file_exists($config['system']['file_list'])) {
            outputError('System file list not found: ' . $config['system']['file_list']);
            $valid = false;
        } elseif (!validateTrustedFile($config['system']['file_list'], false)) {
            $valid = false;
        }
        if (is_string($config['system']['file_list'] ?? null) && $config['system']['file_list'] !== '') {
            $localFileList = $config['system']['file_list'] . '.local';
            if (file_exists($localFileList) && !validateTrustedFile($localFileList, false)) {
                $valid = false;
            }
        }
    }

    if ($config['control_panel']['enabled'] || $config['customers']['enabled']) {
        if (
            !is_string($config['control_panel']['path'] ?? null)
            || !is_dir($config['control_panel']['path'])
        ) {
            outputError(
                'Froxlor installation path does not exist or is not a directory.',
                'Check control_panel.path in config.local.php.'
            );
            $valid = false;
        } else {
            if (!validateFroxlorInputs($config['control_panel']['path'])) {
                $valid = false;
            }
            if (!isPhpFunctionAvailable('proc_open')) {
                outputError(
                    'CLI PHP cannot run the isolated Froxlor settings reader.',
                    'Enable proc_open in the CLI PHP configuration and rerun --check-install.'
                );
                $valid = false;
            }
            if (
                $config['control_panel']['enabled'] && is_string($config['system']['dir'] ?? null)
                && !isDestinationOutsideSource($config['system']['dir'], $config['control_panel']['path'])
            ) {
                outputError('System backup destination overlaps control panel source');
                $valid = false;
            }
        }
        if (!extension_loaded('pdo_mysql')) {
            outputError('PDO MySQL extension is required');
            $valid = false;
        }
    }

    // Required binaries
    $method = $config['archive_method'] ?? null;
    $needsArchive = ($config['customers']['enabled']
        && ($config['customers']['vhosts']['enabled'] || $config['customers']['logs']
            || $config['customers']['mails'] || $config['customers']['databases']))
        || $config['system']['enabled'] || $config['control_panel']['enabled'];
    if ($needsArchive && $method === '7z' && find7zBinary() === '') {
        outputError('7-Zip not found. Install 7zip (apt install 7zip) or p7zip-full (apt install p7zip-full)');
        $valid = false;
    }
    if ($needsArchive && $method === 'tar' && findBinary('tar') === '') {
        outputError('tar not found');
        $valid = false;
    }

    $needsMysqldump = ($config['customers']['enabled'] && $config['customers']['databases'])
        || $config['control_panel']['enabled'];
    if ($needsMysqldump && findBinary('mysqldump') === '') {
        outputError('mysqldump not found. Install mariadb-client or mysql-client');
        $valid = false;
    }
    if ($needsMysqldump) {
        $recoveryRoot = $projectDir . '/recovery';
        if (file_exists($recoveryRoot) || is_link($recoveryRoot)) {
            if (!ensureSecureRuntimeDirectory($recoveryRoot, false)) {
                $valid = false;
            }
        } elseif (!is_writable($projectDir)) {
            outputError('Application directory cannot create private database recovery files');
            $valid = false;
        }
    }

    if ($config['rsync']['enabled']) {
        $hostname = trim(gethostname()) ?: 'localhost';
        if (
            ($config['rsync']['path_customers'] === '' || $config['rsync']['path_system'] === '')
            && preg_match('/^[A-Za-z0-9.-]+$/D', $hostname) !== 1
        ) {
            outputError('Hostname is unsafe for default rsync paths');
            $valid = false;
        }
        if (
            !is_string($config['rsync']['ssh_host'] ?? null)
            || preg_match('/^[A-Za-z0-9_.@-]+$/D', $config['rsync']['ssh_host']) !== 1
            || $config['rsync']['ssh_host'][0] === '-'
        ) {
            outputError('Invalid rsync SSH host alias');
            $valid = false;
        }
        foreach (['path_customers', 'path_system'] as $key) {
            $path = $config['rsync'][$key] ?? null;
            if (!is_string($path) || ($path !== '' && !isSafeRemotePath($path))) {
                outputError('Invalid rsync.' . $key);
                $valid = false;
            }
        }
        if (findBinary('rsync') === '') {
            outputError('rsync not found. Install rsync (apt-get install rsync)');
            $valid = false;
        }
        if (findBinary('ssh') === '') {
            outputError('ssh not found. Install openssh-client');
            $valid = false;
        }
    }

    if ($config['s3']['enabled']) {
        $hostname = trim(gethostname()) ?: 'localhost';
        if (
            ($config['s3']['path_customers'] === '' || $config['s3']['path_system'] === '')
            && preg_match('/^[A-Za-z0-9.-]+$/D', $hostname) !== 1
        ) {
            outputError('Hostname is unsafe for default S3 paths');
            $valid = false;
        }
        $needsBucket = ($config['customers']['enabled'] && ($config['s3']['path_customers'] ?? null) === '')
            || (($config['system']['enabled'] || $config['control_panel']['enabled'])
                && ($config['s3']['path_system'] ?? null) === '');
        if (
            $needsBucket && (!is_string($config['s3']['bucket'] ?? null)
            || !isSafeS3Path($config['s3']['bucket']))
        ) {
            outputError('Invalid S3 bucket');
            $valid = false;
        }
        foreach (['path_customers', 'path_system'] as $key) {
            $path = $config['s3'][$key] ?? null;
            if (!is_string($path) || ($path !== '' && !isSafeS3Path($path))) {
                outputError('Invalid s3.' . $key);
                $valid = false;
            }
        }
        if (findBinary('s3cmd') === '') {
            outputError('s3cmd not found. See http://s3tools.org/download');
            $valid = false;
        }
    }

    // SMTP config completeness
    if ($config['email']['enabled'] || $sendEmail) {
        if (!isPhpFunctionAvailable('stream_socket_client')) {
            outputError('CLI PHP stream socket support is required for SMTP reports.');
            $valid = false;
        }
        foreach (['host', 'user', 'password'] as $key) {
            if (
                !is_string($config['email']['smtp'][$key] ?? null)
                || $config['email']['smtp'][$key] === ''
            ) {
                outputError('Email enabled but smtp.' . $key . ' is empty');
                $valid = false;
            }
        }
        $smtp = $config['email']['smtp'];
        if (
            !is_string($smtp['host'] ?? null)
            || preg_match('/^[A-Za-z0-9.-]+$/D', $smtp['host']) !== 1
            || !is_int($smtp['port'] ?? null) || $smtp['port'] < 1 || $smtp['port'] > 65535
        ) {
            outputError('Invalid SMTP host or port');
            $valid = false;
        }
        if (
            !is_string($config['email']['from'] ?? null) || !is_string($config['email']['to'] ?? null)
            || $config['email']['from'] === '' || $config['email']['to'] === ''
        ) {
            outputError('Email enabled but from/to address is empty');
            $valid = false;
        } elseif (!isValidEmailAddress($config['email']['from']) || !isValidEmailAddress($config['email']['to'])) {
            outputError('Email from/to address is invalid');
            $valid = false;
        }
        if (
            !is_string($config['email']['subject'] ?? null)
            || !isValidHeaderValue($config['email']['subject'])
            || strlen($config['email']['subject']) > 200
            || preg_match('//u', $config['email']['subject']) !== 1
        ) {
            outputError('Email subject contains an invalid header character');
            $valid = false;
        } else {
            $rendered = str_replace(
                ['{hostname}', '{date}'],
                [trim(gethostname()) ?: 'localhost', date('Y-m-d')],
                $config['email']['subject']
            );
            if (!isValidHeaderValue($rendered) || strlen($rendered) > 240) {
                outputError('Rendered email subject is invalid or too long');
                $valid = false;
            }
        }
        if (!in_array($config['email']['smtp']['encryption'] ?? 'tls', ['tls', 'ssl', ''], true)) {
            outputError('Email smtp.encryption must be tls, ssl, or empty');
            $valid = false;
        }
        if (($smtp['encryption'] ?? 'tls') !== '' && !extension_loaded('openssl')) {
            outputError('OpenSSL extension is required for encrypted SMTP');
            $valid = false;
        }
    }

    return $valid;
}

/**
 * Validate a trusted input file
 *
 * @param string  $path   Input path
 * @param boolean $secret Whether the file contains secrets
 *
 * @return boolean
 */
function validateTrustedFile(string $path, bool $secret): bool
{
    if (is_link($path) || !is_file($path)) {
        outputError(
            'Backup input is missing, not a regular file, or is a symlink: ' . $path,
            'Check the backup installation path and replace symlinks with regular files.'
        );
        return false;
    }
    $permissions = fileperms($path);
    $forbidden = $secret ? 0077 : 0022;
    if ($permissions === false || ($permissions & $forbidden) !== 0) {
        outputError(
            'Backup input permissions are unsafe: ' . $path,
            $secret ? 'Set this backup configuration file to mode 0600 or stricter.' : 'Remove group and other write permissions from this backup input.'
        );
        return false;
    }
    if (isRootProcess() && fileowner($path) !== 0) {
        outputError(
            'Backup input must be root-owned when backup runs as root: ' . $path,
            'Check who owns this backup file; do not change Froxlor application file ownership.'
        );
        return false;
    }
    $parent = dirname($path);
    while ($parent !== dirname($parent)) {
        if (
            is_link($parent) || !is_dir($parent) || (fileperms($parent) & 0022) !== 0
            || (isRootProcess() && fileowner($parent) !== 0)
        ) {
            outputError(
                'Backup input parent directory is unsafe: ' . $parent,
                'Check for symlinks, group/other write access, and root ownership when running as root.'
            );
            return false;
        }
        $parent = dirname($parent);
    }

    return true;
}

/**
 * Check Froxlor PHP inputs for loading under their owner account
 *
 * @param string $panelPath Froxlor installation path
 *
 * @return boolean
 */
function validateFroxlorInputs(string $panelPath): bool
{
    $userdata = $panelPath . '/lib/userdata.inc.php';
    $tables = $panelPath . '/lib/tables.inc.php';
    foreach ([$userdata, $tables] as $path) {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            outputError(
                'Cannot read a regular Froxlor settings file: ' . $path,
                'Check that it exists, is not a symlink, and is readable by the backup process.'
            );
            return false;
        }
    }
    $owner = fileowner($userdata);
    $tableOwner = fileowner($tables);
    if ($owner === false || $tableOwner === false || ($tableOwner !== $owner && $tableOwner !== 0)) {
        outputError(
            'Froxlor settings files have incompatible owners: ' . $userdata . ' and ' . $tables,
            'Check their ownership against the Froxlor installation; the table file may also be root-owned.'
        );
        return false;
    }
    if ($owner === 0) {
        return validateTrustedFile($userdata, false) && validateTrustedFile($tables, false);
    }
    if (posix_geteuid() !== $owner && !isRootProcess()) {
        outputError(
            'The backup process cannot read Froxlor settings as their owner.',
            'Run the backup as root or as the owner of ' . $userdata . '.'
        );
        return false;
    }
    if (isRootProcess()) {
        $account = posix_getpwuid($owner);
        if ($account === false || !isset($account['name']) || findRunuserBinary() === '') {
            outputError(
                'Cannot switch to the Froxlor settings owner.',
                'Check that the owner has a system account and that root-owned runuser is installed.'
            );
            return false;
        }
    }

    return true;
}

/**
 * Find a root-owned runuser executable outside a caller-controlled PATH
 *
 * @return string Absolute executable path, or empty string
 */
function findRunuserBinary(): string
{
    foreach (['/usr/sbin/runuser', '/usr/bin/runuser', '/sbin/runuser', '/bin/runuser'] as $candidate) {
        $path = realpath($candidate);
        if (
            $path !== false && is_file($path) && is_executable($path)
            && fileowner($path) === 0 && (fileperms($path) & 0022) === 0
        ) {
            return $path;
        }
    }

    return '';
}

/**
 * Read Froxlor connection settings and table names outside the root PHP process
 *
 * @param string $panelPath Froxlor installation path
 *
 * @return array|null Connection settings and table names, or null on failure
 */
function loadFroxlorSettings(string $panelPath): ?array
{
    if (!validateFroxlorInputs($panelPath)) {
        return null;
    }
    if (!function_exists('proc_open')) {
        outputError(
            'PHP cannot start the Froxlor settings reader.',
            'Enable proc_open in the CLI PHP configuration and rerun --check-install.'
        );
        return null;
    }
    $userdata = $panelPath . '/lib/userdata.inc.php';
    $owner = fileowner($userdata);
    $command = [PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', froxlorReaderCode(), $panelPath];
    if (isRootProcess() && $owner !== 0) {
        $account = posix_getpwuid($owner);
        $command = array_merge([findRunuserBinary(), '-u', $account['name'], '--'], $command);
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, '/', ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C']);
    if (!is_resource($process)) {
        outputError(
            'Cannot launch the Froxlor settings reader.',
            'Check CLI PHP proc_open and the Froxlor owner account, then rerun --check-install.'
        );
        return null;
    }
    fclose($pipes[0]);
    $json = stream_get_contents($pipes[1], 1048577);
    fclose($pipes[1]);
    if ($json === false || strlen($json) > 1048576) {
        proc_terminate($process);
        proc_close($process);
        outputError(
            'Froxlor settings reader returned too much data.',
            'Inspect the Froxlor settings files for unexpected output or oversized values.'
        );
        return null;
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        outputError(
            'Froxlor settings reader failed (exit code ' . $exitCode . ').',
            'Check Froxlor settings file readability and PHP syntax as the Froxlor file owner.'
        );
        return null;
    }
    $settings = json_decode($json, true);
    if (
        !is_array($settings) || !is_array($settings['sql'] ?? null)
        || !is_array($settings['sql_root'][0] ?? null) || !is_array($settings['tables'] ?? null)
    ) {
        outputError(
            'Froxlor settings reader did not return the required database settings and table names.',
            'Check the Froxlor settings files for missing or unsupported values.'
        );
        return null;
    }
    foreach (['host', 'db', 'user', 'password'] as $key) {
        if (!is_string($settings['sql'][$key] ?? null)) {
            outputError('Froxlor database setting is invalid: ' . $key);
            return null;
        }
    }
    foreach (['host', 'user', 'password'] as $key) {
        if (!is_string($settings['sql_root'][0][$key] ?? null)) {
            outputError('Froxlor privileged database setting is invalid: ' . $key);
            return null;
        }
    }
    $tableNames = ['TABLE_PANEL_CUSTOMERS', 'TABLE_PANEL_DOMAINS', 'TABLE_PANEL_DATABASES', 'TABLE_PANEL_SETTINGS', 'TABLE_MAIL_USERS'];
    foreach ($tableNames as $name) {
        $table = $settings['tables'][$name] ?? null;
        if (!is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            outputError('Froxlor table name is invalid: ' . $name);
            return null;
        }
        if (defined($name) && constant($name) !== $table) {
            outputError('Froxlor table name conflicts with an existing constant: ' . $name);
            return null;
        }
    }
    foreach ($tableNames as $name) {
        if (!defined($name)) {
            define($name, $settings['tables'][$name]);
        }
    }

    return $settings;
}

/**
 * Return the isolated Froxlor settings reader program
 *
 * @return string
 */
function froxlorReaderCode(): string
{
    return <<<'PHP'
$panelPath = $argv[1];
require $panelPath . '/lib/userdata.inc.php';
require $panelPath . '/lib/tables.inc.php';
$tables = [];
foreach (['TABLE_PANEL_CUSTOMERS', 'TABLE_PANEL_DOMAINS', 'TABLE_PANEL_DATABASES', 'TABLE_PANEL_SETTINGS', 'TABLE_MAIL_USERS'] as $name) {
    if (!defined($name)) {
        exit(2);
    }
    $tables[$name] = constant($name);
}
echo json_encode(['sql' => $sql ?? null, 'sql_root' => $sql_root ?? null, 'tables' => $tables], JSON_THROW_ON_ERROR);
PHP;
}

/**
 * Check whether the process runs as root
 *
 * @return boolean
 */
function isRootProcess(): bool
{
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/**
 * Validate an email header address
 *
 * @param string $value Email address
 *
 * @return boolean
 */
function isValidEmailAddress(string $value): bool
{
    return isValidHeaderValue($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate a mail header value
 *
 * @param string $value Header value
 *
 * @return boolean
 */
function isValidHeaderValue(string $value): bool
{
    return preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
}

/**
 * Validate a relative vhost exclusion path
 *
 * @param string $path Exclusion path
 *
 * @return boolean
 */
function isValidExcludePath(string $path): bool
{
    if ($path === '' || strpos($path, "\0") !== false) {
        return false;
    }

    foreach (explode('/', $path) as $component) {
        if ($component === '' || $component === '.' || $component === '..') {
            return false;
        }
    }

    return true;
}

// =============================================================================
// SMTP
// =============================================================================

/**
 * Read a complete SMTP reply, including continuation lines
 *
 * @param resource $socket SMTP stream
 *
 * @return integer|null Reply code or null on protocol/I/O failure
 */
function smtpReadReply($socket): ?int
{
    $expected = null;
    for ($lineCount = 0; $lineCount < 100; $lineCount++) {
        $line = fgets($socket, 512);
        if ($line === false || preg_match('/^([0-9]{3})([ -])/', $line, $matches) !== 1) {
            return null;
        }
        $code = (int) $matches[1];
        if ($expected !== null && $expected !== $code) {
            return null;
        }
        $expected = $code;
        if ($matches[2] === ' ') {
            return $code;
        }
    }

    return null;
}

/**
 * Write all bytes to a stream, including partial writes
 *
 * @param resource $socket Writable stream
 * @param string   $data   Bytes to send
 *
 * @return boolean
 */
function writeAllStream($socket, string $data): bool
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset, 8192));
        if ($written === false || $written === 0) {
            return false;
        }
        $offset += $written;
    }

    return true;
}

/**
 * Send a command and read its complete reply
 *
 * @param resource $socket  SMTP stream
 * @param string   $command SMTP command
 *
 * @return integer|null
 */
function smtpCommand($socket, string $command): ?int
{
    return writeAllStream($socket, $command . "\r\n") ? smtpReadReply($socket) : null;
}

/**
 * Encode non-ASCII header text as folded UTF-8 encoded words
 *
 * @param string $value Header text
 *
 * @return string
 */
function smtpHeaderText(string $value): string
{
    if (preg_match('/^[\x20-\x7e]*$/D', $value) === 1) {
        return $value;
    }
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) {
        throw new \RuntimeException('Invalid UTF-8 in SMTP subject');
    }
    $chunks = [];
    $chunk = '';
    foreach ($characters as $character) {
        if (strlen($chunk . $character) > 36) {
            $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $chunk = '';
        }
        $chunk .= $character;
    }
    if ($chunk !== '') {
        $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
    }

    return implode("\r\n ", $chunks);
}

/**
 * Extract the backup summary for email previews
 *
 * @param string $body Buffered report
 *
 * @return string Summary, or empty when absent
 */
function reportPreheader(string $body): string
{
    foreach (explode("\n", $body) as $line) {
        if (strpos($line, '] Success:') !== false || strpos($line, '] Errors:') !== false) {
            return trim(preg_replace('/^\[\d{2}:\d{2}:\d{2}\]\s*/', '', $line));
        }
    }

    return '';
}

/**
 * Wrap plain-text backup log in a minimal responsive HTML email template
 *
 * Converts the structured plain-text output (timestamps, section headers,
 * ERROR/Summary lines) into styled HTML. The plain-text body is preserved
 * as the text/plain part of the multipart email.
 *
 * @param string  $plainBody Plain-text backup log from outputGet()
 * @param boolean $hasErrors True if any errors occurred during the run
 *
 * @return string HTML email body
 */
function wrapEmailHtml(string $plainBody, bool $hasErrors): string
{
    $statusColor = $hasErrors ? '#c0392b' : '#27ae60';
    $statusLabel = $hasErrors ? 'Completed with errors' : 'Completed successfully';

    // Convert plain-text lines to styled HTML rows
    $rows = '';
    foreach (explode("\n", $plainBody) as $line) {
        $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        // Plain dashes line — outputSeparator() standalone rule, render as HR
        if (preg_match('/^-{10,}$/', $line)) {
            $rows .= '<tr><td style="padding:0;border-top:1px solid #e0e0e0;font-size:0;line-height:0;"></td></tr>';
            continue;
        }

        // Section divider dashes inside outputSection() blocks — skip, handled by header detection
        if (strpos($line, '---') === 0 || (strpos($line, '-') === 0 && strlen($line) > 20)) {
            continue;
        }

        // Detect section header (line between two dashes-lines): "  SECTION NAME"
        if (preg_match('/^\s{2}[A-Z][A-Z\s:\d]+$/', $line)) {
            $rows .= '<tr><td style="padding:14px 20px 6px;font-family:monospace,monospace;'
                . 'font-size:11px;font-weight:bold;color:#555;letter-spacing:1px;white-space:pre-wrap;'
                . 'border-top:2px solid #e0e0e0;text-transform:uppercase;">'
                . trim($line) . '</td></tr>';
            continue;
        }

        // Detect error lines
        if (strpos($line, 'ERROR:') !== false) {
            $rows .= '<tr><td style="padding:3px 20px;font-family:monospace,monospace;'
                . 'font-size:12px;color:#c0392b;background:#fff5f5;white-space:pre-wrap;">' . $line . '</td></tr>';
            continue;
        }

        // Detect summary line
        if (strpos($line, '] Success:') !== false || strpos($line, '] Errors:') !== false) {
            $rows .= '<tr><td style="padding:6px 20px;font-family:monospace,monospace;'
                . 'font-size:12px;font-weight:bold;color:#2c3e50;border-top:1px solid #e0e0e0;white-space:pre-wrap;">'
                . $line . '</td></tr>';
            continue;
        }

        // Empty lines — small spacer
        if (trim($line) === '') {
            $rows .= '<tr><td style="padding:3px 0;"></td></tr>';
            continue;
        }

        // Normal lines
        $rows .= '<tr><td style="padding:3px 20px;font-family:monospace,monospace;'
            . 'font-size:12px;color:#333;white-space:pre-wrap;">' . $line . '</td></tr>';
    }

    $preheader = reportPreheader($plainBody) ?: $statusLabel;

    $preheaderHtml = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'
        . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8')
        // Filler to prevent email clients from pulling the next visible text into the preview
        . str_repeat('&nbsp;&#847;', 80)
        . '</div>';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f4;">'
        . $preheaderHtml
        . '<table width="100%" cellpadding="0" cellspacing="0" border="0"'
        . ' style="background:#f4f4f4;padding:20px 0;">'
        . '<tr><td align="center">'
        . '<table width="100%" cellpadding="0" cellspacing="0" border="0"'
        . ' style="max-width:700px;background:#fff;border-radius:4px;'
        . 'border:1px solid #ddd;border-collapse:collapse;">'

        // Status bar
        . '<tr><td style="background:' . $statusColor . ';padding:10px 20px;border-radius:4px 4px 0 0;'
        . 'border-bottom:2px solid #e0e0e0;">'
        . '<span style="font-family:sans-serif;font-size:13px;font-weight:bold;color:#fff;">'
        . $statusLabel . '</span></td></tr>'

        // Log body
        . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" border="0">'
        . $rows
        . '</table></td></tr>'

        // Footer
        . '<tr><td style="padding:10px 20px;border-top:1px solid #e0e0e0;">'
        . '<span style="font-family:sans-serif;font-size:11px;color:#999;">'
        . 'Froxlor Backup</span></td></tr>'

        . '</table></td></tr></table></body></html>';
}

/**
 * Send an email via raw SMTP — no external dependencies
 *
 * Supports plain, STARTTLS (port 587), and SSL (port 465) connections.
 * Sends a multipart/alternative message with both text/plain and text/html parts.
 *
 * @param array  $smtpConfig Keys: host, port, user, password, encryption ('tls'|'ssl'|'')
 * @param string $from       Sender address
 * @param string $to         Recipient address
 * @param string $subject    Email subject
 * @param string $body       Plain-text email body
 *
 * @return boolean True on success
 */
function smtpSend(array $smtpConfig, string $from, string $to, string $subject, string $body): bool
{
    if (
        !isValidEmailAddress($from) || !isValidEmailAddress($to)
        || !isValidHeaderValue($subject) || strlen($subject) > 256
    ) {
        outputError('Invalid SMTP address or subject');
        return false;
    }
    $host       = $smtpConfig['host'];
    $port       = (int) $smtpConfig['port'];
    $user       = $smtpConfig['user'];
    $password   = $smtpConfig['password'];
    $encryption = $smtpConfig['encryption'] ?? 'tls';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $host,
            'allow_self_signed' => false,
        ],
    ]);
    $transport = ($encryption === 'ssl') ? 'tls://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$socket) {
        outputError('SMTP connect failed: ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, 30);
    $identity = trim(gethostname()) ?: 'localhost';
    if (preg_match('/^[A-Za-z0-9.-]+$/D', $identity) !== 1) {
        $identity = 'localhost';
    }
    if (smtpReadReply($socket) !== 220 || smtpCommand($socket, 'EHLO ' . $identity) !== 250) {
        outputError('SMTP greeting or EHLO failed');
        fclose($socket);
        return false;
    }

    // STARTTLS upgrade
    if ($encryption === 'tls') {
        $code = smtpCommand($socket, 'STARTTLS');
        if ($code !== 220) {
            outputError('SMTP STARTTLS failed (' . $code . ')');
            fclose($socket);
            return false;
        }
        if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            outputError('SMTP TLS negotiation failed');
            fclose($socket);
            return false;
        }
        if (smtpCommand($socket, 'EHLO ' . $identity) !== 250) {
            outputError('SMTP EHLO after TLS failed');
            fclose($socket);
            return false;
        }
    }

    if (
        smtpCommand($socket, 'AUTH LOGIN') !== 334
        || smtpCommand($socket, base64_encode($user)) !== 334
    ) {
        outputError('SMTP authentication challenge failed');
        fclose($socket);
        return false;
    }
    $code = smtpCommand($socket, base64_encode($password));
    if ($code !== 235) {
        outputError('SMTP authentication failed (' . $code . ') -- check user/password');
        fclose($socket);
        return false;
    }

    // Envelope
    $code = smtpCommand($socket, 'MAIL FROM:<' . $from . '>');
    if ($code !== 250) {
        outputError('SMTP MAIL FROM rejected (' . $code . ')');
        fclose($socket);
        return false;
    }
    $code = smtpCommand($socket, 'RCPT TO:<' . $to . '>');
    if ($code !== 250) {
        outputError('SMTP RCPT TO rejected (' . $code . ')');
        fclose($socket);
        return false;
    }
    $code = smtpCommand($socket, 'DATA');
    if ($code !== 354) {
        outputError('SMTP DATA rejected (' . $code . ')');
        fclose($socket);
        return false;
    }

    // Build multipart/alternative message (plain + HTML)
    $boundary = 'bp_' . md5(uniqid('', true));
    $htmlBody = wrapEmailHtml($body, outputHasErrors());

    $plainPreheader = reportPreheader($body);
    $plainPreheader = $plainPreheader !== '' ? $plainPreheader . "\n\n" : '';
    $plainBody = $plainPreheader . $body;

    $date    = date('r');
    $message = 'Date: ' . $date . "\r\n"
        . 'From: ' . $from . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . smtpHeaderText($subject) . "\r\n"
        . 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n"
        . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n"
        . "\r\n"
        . chunk_split(base64_encode($plainBody), 76, "\r\n")
        . '--' . $boundary . "\r\n"
        . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n"
        . "\r\n"
        . chunk_split(base64_encode($htmlBody), 76, "\r\n")
        . '--' . $boundary . '--';
    if (!writeAllStream($socket, $message . "\r\n.\r\n")) {
        outputError('SMTP message write failed');
        fclose($socket);
        return false;
    }
    $code = smtpReadReply($socket);
    if ($code !== 250) {
        outputError('SMTP message rejected (' . $code . ')');
        fclose($socket);
        return false;
    }

    writeAllStream($socket, 'QUIT' . "\r\n");
    fclose($socket);

    return true;
}
