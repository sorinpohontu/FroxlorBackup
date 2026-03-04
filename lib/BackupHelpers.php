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
 * @since       2026.03.04
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
 * Output elapsed time since $startTime
 *
 * @param float $startTime Result of microtime(true) at step start
 *
 * @return void
 */
function outputDone(float $startTime): void
{
    $elapsed = microtime(true) - $startTime;
    $msg     = 'Done. (' . number_format($elapsed, 2) . ' sec';
    if ($elapsed >= 60) {
        $msg .= ' / ' . number_format($elapsed / 60, 2) . ' min';
    }
    output($msg . ')');
}

/**
 * Output an error message and increment the error counter
 *
 * @param string $msg Error message
 *
 * @return void
 */
function outputError(string $msg): void
{
    global $_errorCount;
    $_errorCount++;
    output('ERROR: ' . $msg);
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
    $fp = fopen($lockFile, 'w');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        return false;
    }
    fwrite($fp, (string) getmypid());
    fflush($fp);
    return $fp;
}

/**
 * Release the file lock and delete the lock file
 *
 * @param resource $fp       File pointer returned by acquireLock()
 * @param string   $lockFile Path to lock file
 *
 * @return void
 */
function releaseLock($fp, string $lockFile): void
{
    flock($fp, LOCK_UN);
    fclose($fp);
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
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
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

/**
 * Find a binary using `which`, with result caching
 *
 * @param string $name Binary name (e.g. 'tar', '7z', 'rsync')
 *
 * @return string Full path, or empty string if not found
 */
function findBinary(string $name): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $cache[$name] = trim(shell_exec('which ' . escapeshellarg($name)) ?? '');
    }

    return $cache[$name];
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

// =============================================================================
// ARCHIVE
// =============================================================================

/**
 * Archive a directory using tar or 7z
 *
 * @param string $source Full path to source directory
 * @param string $dest   Destination path without extension
 * @param string $method Archive method: 'tar' or '7z'
 *
 * @return boolean True if archive was created successfully
 */
function archiveDirectory(string $source, string $dest, string $method): bool
{
    if (!is_dir($source)) {
        outputError('Directory not found: ' . $source);
        return false;
    }
    if ($method === '7z') {
        $bin = requireBinary('7z');
        if ($bin === '') {
            return false;
        }
        $archiveFile = $dest . '.7z';
        if (file_exists($archiveFile)) {
            unlink($archiveFile);
        }
        shell_exec(
            $bin . ' a -mx=5 -bso0 -bsp0 '
            . escapeshellarg($archiveFile) . ' '
            . escapeshellarg($source . '/*')
        );
        return file_exists($archiveFile);
    }
    // Default: tar
    $archiveFile = $dest . '.tar.gz';
    shell_exec(
        'tar -C ' . escapeshellarg($source)
        . ' -czf ' . escapeshellarg($archiveFile)
        . ' --ignore-failed-read --warning=none .'
    );
    return file_exists($archiveFile);
}

/**
 * Archive a list of files using tar or 7z, then delete the source files
 *
 * @param string[] $files  Absolute paths to files to include
 * @param string   $dest   Destination path without extension
 * @param string   $method Archive method: 'tar' or '7z'
 *
 * @return boolean True if archive was created successfully
 */
function archiveFiles(array $files, string $dest, string $method): bool
{
    if ($method === '7z') {
        $bin = requireBinary('7z');
        if ($bin === '') {
            return false;
        }
        $archiveFile = $dest . '.7z';
        if (file_exists($archiveFile)) {
            unlink($archiveFile);
        }
        $fileArgs = implode(' ', array_map('escapeshellarg', $files));
        shell_exec($bin . ' a -mx=5 -bso0 -bsp0 ' . escapeshellarg($archiveFile) . ' ' . $fileArgs);
        $result = file_exists($archiveFile);
    } else {
        // tar — archive relative to the common directory to avoid absolute path warnings
        $archiveFile = $dest . '.tar.gz';
        $dir         = escapeshellarg(dirname(reset($files)));
        $fileArgs    = implode(' ', array_map(fn($f) => escapeshellarg(basename($f)), $files));
        shell_exec('tar -czf ' . escapeshellarg($archiveFile) . ' -C ' . $dir . ' ' . $fileArgs);
        $result = file_exists($archiveFile);
    }
    // Cleanup source files
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
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
    if ($method === '7z') {
        $bin = requireBinary('7z');
        if ($bin === '') {
            return false;
        }
        $archiveFile = $dest . '.7z';
        if (file_exists($archiveFile)) {
            unlink($archiveFile);
        }
        $fileArgs = implode(' ', array_map('escapeshellarg', $files));
        shell_exec($bin . ' a -mx=5 -bso0 -bsp0 ' . escapeshellarg($archiveFile) . ' ' . $fileArgs);
        return file_exists($archiveFile);
    }
    // Default: tar
    $archiveFile = $dest . '.tar.gz';
    $fileArgs    = implode(' ', array_map('escapeshellarg', $files));
    shell_exec('tar -czf ' . escapeshellarg($archiveFile) . ' ' . $fileArgs);
    return file_exists($archiveFile);
}

/**
 * Archive files listed in a text file using tar or 7z
 *
 * Used for system config backup where paths/globs are listed one per line.
 *
 * @param string $fileListPath Path to the file containing paths/globs to archive
 * @param string $dest         Destination path without extension
 * @param string $method       Archive method: 'tar' or '7z'
 *
 * @return boolean True if archive was created successfully
 */
function archiveFileList(string $fileListPath, string $dest, string $method): bool
{
    if ($method === '7z') {
        $bin = requireBinary('7z');
        if ($bin === '') {
            return false;
        }
        $archiveFile = $dest . '.7z';
        if (file_exists($archiveFile)) {
            unlink($archiveFile);
        }
        // 7z supports list files with @
        // -spf: preserve full absolute paths (including leading /) for correct extraction to /
        // -bso0: suppress normal output, -bsp0: suppress progress, -bse0: suppress warnings
        // Missing paths in the file list are expected — archive creation check handles real failures
        shell_exec(
            $bin . ' a -mx=5 -spf -bso0 -bsp0 -bse0 '
            . escapeshellarg($archiveFile)
            . ' @' . escapeshellarg($fileListPath)
        );
        return file_exists($archiveFile);
    }
    // Default: tar
    $archiveFile = $dest . '.tar.gz';
    shell_exec(
        'tar -czf ' . escapeshellarg($archiveFile)
        . ' --ignore-failed-read --warning=none --wildcards'
        . ' $(cat ' . escapeshellarg($fileListPath) . ') 2>/dev/null'
    );
    return file_exists($archiveFile);
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
 * Runs mysqldump, writes a grants file, archives both, then deletes the originals.
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
 * @return void
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
): void {
    $archiveName = $archiveName !== '' ? $archiveName : $dbName;
    $sqlFile     = $destDir . '/' . $dbName . '.sql';
    $grantFile   = $destDir . '/' . $dbName . '-grants.sql';

    // Dump database
    $mysqldump = requireBinary('mysqldump');
    if ($mysqldump === '') {
        return;
    }
    shell_exec(
        $mysqldump . ' --opt --force --allow-keywords'
        . ' -h ' . escapeshellarg($sqlHost)
        . ' ' . escapeshellarg($dbName)
        . ' -r ' . escapeshellarg($sqlFile)
    );

    // Write grants file
    dumpGrants($dbRoot, $dbUser, $dbHost, $grantFile);

    // Archive + cleanup
    archiveFiles([$sqlFile, $grantFile], $destDir . '/' . $archiveName, $method);
}

/**
 * Write a SHOW GRANTS result to a SQL file
 *
 * @param \PDO   $dbRoot   Root PDO connection
 * @param string $user     Database user
 * @param string $host     Database host
 * @param string $destFile Destination file path
 *
 * @return void
 */
function dumpGrants(\PDO $dbRoot, string $user, string $host, string $destFile): void
{
    $grants = dbRunSQL($dbRoot, 'SHOW GRANTS FOR \'' . $user . '\'@\'' . $host . '\'');
    $fp     = fopen($destFile, 'w');
    if (!$fp) {
        outputError('Cannot write grants file: ' . $destFile);
        return;
    }
    foreach ($grants as $grant) {
        foreach ($grant as $comment => $value) {
            fwrite($fp, '# ' . $comment . "\n");
            fwrite($fp, $value . ";\n\r");
        }
    }
    fclose($fp);
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
 * @param string $params    Rsync parameters
 *
 * @return boolean True on success
 */
function doRsync(string $host, string $localDir, string $remoteDir, string $params): bool
{
    $ssh   = requireBinary('ssh');
    $rsync = requireBinary('rsync');
    if ($ssh === '' || $rsync === '') {
        return false;
    }
    // Ensure remote destination exists
    shell_exec($ssh . ' ' . escapeshellarg($host) . ' mkdir -p ' . escapeshellarg($remoteDir));

    // Sync
    shell_exec(
        $rsync . ' ' . $params . ' '
        . escapeshellarg($localDir . '/') . ' '
        . escapeshellarg($host . ':' . $remoteDir . '/')
    );

    return true;
}

/**
 * List directories/files in a remote rsync path via SSH
 *
 * @param string $host      SSH host alias
 * @param string $remoteDir Remote directory path
 *
 * @return string[] List of entries
 */
function rsyncList(string $host, string $remoteDir): array
{
    $ssh = requireBinary('ssh');
    if ($ssh === '') {
        return [];
    }
    $output = shell_exec($ssh . ' ' . escapeshellarg($host) . ' ls ' . escapeshellarg($remoteDir) . ' 2>/dev/null');
    if ($output === null || $output === '') {
        return [];
    }

    return array_filter(explode(PHP_EOL, rtrim(str_replace(' ', PHP_EOL, $output))));
}

/**
 * Delete files/directories on a remote host via SSH
 *
 * @param string   $host      SSH host alias
 * @param string   $remoteDir Remote base directory
 * @param string[] $files     Entries (relative to $remoteDir) to delete
 *
 * @return void
 */
function rsyncDeleteFiles(string $host, string $remoteDir, array $files): void
{
    if (empty($files)) {
        return;
    }
    $ssh = requireBinary('ssh');
    if ($ssh === '') {
        return;
    }
    foreach ($files as $file) {
        shell_exec(
            $ssh . ' ' . escapeshellarg($host)
            . ' rm -rf ' . escapeshellarg($remoteDir . '/' . $file)
        );
    }
}

// =============================================================================
// SYNC: S3
// =============================================================================

/**
 * Sync a local directory to an S3 path using s3cmd
 *
 * @param string $localDir  Local source directory
 * @param string $remoteDir S3 destination path
 * @param string $params    S3cmd parameters
 *
 * @return boolean True on success
 */
function doS3Sync(string $localDir, string $remoteDir, string $params): bool
{
    $bin = requireBinary('s3cmd');
    if ($bin === '') {
        return false;
    }
    shell_exec(
        $bin . ' ' . $params . ' '
        . escapeshellarg($localDir . '/') . ' '
        . $remoteDir . '/'
    );
    return true;
}

/**
 * List objects in an S3 path using s3cmd
 *
 * @param string $remoteDir S3 path
 *
 * @return string[] List of object keys
 */
function s3List(string $remoteDir): array
{
    $bin = requireBinary('s3cmd');
    if ($bin === '') {
        return [];
    }
    $output = shell_exec($bin . ' ls --recursive ' . $remoteDir . ' | awk \'{print $4}\'');
    if ($output === null || $output === '') {
        return [];
    }

    return array_filter(explode(PHP_EOL, rtrim($output)));
}

/**
 * Delete objects from S3 using s3cmd
 *
 * @param string[] $files Full S3 object paths to delete
 *
 * @return void
 */
function s3DeleteFiles(array $files): void
{
    if (empty($files)) {
        return;
    }
    $bin = requireBinary('s3cmd');
    if ($bin === '') {
        return;
    }
    foreach ($files as $file) {
        shell_exec($bin . ' del ' . escapeshellarg($file));
    }
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
function cleanLocalBackups(string $baseDir, string $today, int $days): int
{
    if (!is_dir($baseDir)) {
        return 0;
    }

    $removed         = 0;
    $cutoffTimestamp = strtotime($today . ' midnight -' . $days . ' days');
    $entries         = scandir($baseDir);

    foreach ($entries as $entry) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry)) {
            continue;
        }
        $entryTimestamp = strtotime($entry . ' midnight');
        if ($entryTimestamp <= $cutoffTimestamp) {
            $path = $baseDir . '/' . $entry;
            if (is_dir($path)) {
                shell_exec('rm -rf ' . escapeshellarg($path));
                $removed++;
            }
        }
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
        preg_match('/\d{4}-\d{2}-\d{2}/', $file, $matches);
        if (!empty($matches)) {
            $fileTimestamp = strtotime($matches[0] . ' midnight');
            if ($fileTimestamp <= $cutoffTimestamp) {
                $deletables[] = $file;
            }
        }
    }

    return $deletables;
}

// =============================================================================
// SUMMARY
// =============================================================================

// @var array<string, int> Summary counters keyed by label
$_summary = [];

// @var array<string, float> Per-step elapsed times in seconds keyed by step label
$_stepTimes = [];

/**
 * Reset the summary counters and step timers
 *
 * @return void
 */
function summaryInit(): void
{
    global $_summary, $_stepTimes;
    $_summary   = [];
    $_stepTimes = [];
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
 * Record elapsed time for a top-level step
 *
 * @param string $label     Step label (e.g. 'Customers', 'System')
 * @param float  $stepStart Result of microtime(true) captured before the step ran
 *
 * @return void
 */
function summaryTime(string $label, float $stepStart): void
{
    global $_stepTimes;
    $_stepTimes[$label] = microtime(true) - $stepStart;
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

    return implode(', ', $parts);
}

/**
 * Return per-step timing as a human-readable string
 *
 * @return string e.g. "Customers 4.12s  System 0.84s  Rsync 8.21s"
 */
function summaryTimingGet(): string
{
    global $_stepTimes;
    $parts = [];
    foreach ($_stepTimes as $label => $elapsed) {
        $entry = $label . ' ' . number_format($elapsed, 2) . 's';
        if ($elapsed >= 60) {
            $entry .= ' (' . number_format($elapsed / 60, 2) . ' min)';
        }
        $parts[] = $entry;
    }

    return implode('  |  ', $parts);
}

// =============================================================================
// CONFIG VALIDATION
// =============================================================================

/**
 * Validate the merged config array and output errors for any issues found
 *
 * @param array $config Merged config array
 *
 * @return boolean True if config is valid
 */
function validateConfig(array $config): bool
{
    $valid = true;

    // Backup directories writable (or creatable)
    if ($config['customers']['enabled']) {
        $dir = $config['customers']['dir'];
        if (is_dir($dir) && !is_writable($dir)) {
            outputError('Customers backup dir not writable: ' . $dir);
            $valid = false;
        }
    }

    if ($config['system']['enabled']) {
        if (empty($config['system']['file_list'])) {
            outputWarn('System file list not configured — system backup will be skipped');
        } elseif (!file_exists($config['system']['file_list'])) {
            outputWarn('System file list not found: ' . $config['system']['file_list'] . ' — system backup will be skipped');
        }
    }

    if ($config['control_panel']['enabled']) {
        if (!is_dir($config['control_panel']['path'])) {
            outputError('Control panel path not found: ' . $config['control_panel']['path']);
            $valid = false;
        }
    }

    // Required binaries
    $method = $config['archive_method'];
    if ($method === '7z' && findBinary('7z') === '') {
        outputError('7z not found. Install p7zip-full (apt-get install p7zip-full)');
        $valid = false;
    }

    $needsMysqldump = ($config['customers']['enabled'] && $config['customers']['databases'])
        || $config['control_panel']['enabled'];
    if ($needsMysqldump && findBinary('mysqldump') === '') {
        outputError('mysqldump not found. Install mariadb-client or mysql-client');
        $valid = false;
    }

    if ($config['rsync']['enabled']) {
        if (findBinary('rsync') === '') {
            outputError('rsync not found. Install rsync (apt-get install rsync)');
            $valid = false;
        }
        if (findBinary('ssh') === '') {
            outputError('ssh not found. Install openssh-client');
            $valid = false;
        }
    }

    if ($config['s3']['enabled'] && findBinary('s3cmd') === '') {
        outputError('s3cmd not found. See http://s3tools.org/download');
        $valid = false;
    }

    // SMTP config completeness
    if ($config['email']['enabled']) {
        foreach (['host', 'user', 'password'] as $key) {
            if (empty($config['email']['smtp'][$key])) {
                outputError('Email enabled but smtp.' . $key . ' is empty');
                $valid = false;
            }
        }
        if (empty($config['email']['from']) || empty($config['email']['to'])) {
            outputError('Email enabled but from/to address is empty');
            $valid = false;
        }
    }

    return $valid;
}

// =============================================================================
// SMTP
// =============================================================================

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
                . 'font-size:11px;font-weight:bold;color:#555;letter-spacing:1px;'
                . 'border-top:2px solid #e0e0e0;text-transform:uppercase;">'
                . trim($line) . '</td></tr>';
            continue;
        }

        // Detect error lines
        if (strpos($line, 'ERROR:') !== false) {
            $rows .= '<tr><td style="padding:3px 20px;font-family:monospace,monospace;'
                . 'font-size:12px;color:#c0392b;background:#fff5f5;">' . $line . '</td></tr>';
            continue;
        }

        // Detect summary line
        if (strpos($line, '] Success:') !== false || strpos($line, '] Errors:') !== false) {
            $rows .= '<tr><td style="padding:6px 20px;font-family:monospace,monospace;'
                . 'font-size:12px;font-weight:bold;color:#2c3e50;border-top:1px solid #e0e0e0;">'
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
            . 'font-size:12px;color:#333;">' . $line . '</td></tr>';
    }

    // Extract summary line for the preheader (shown in notification previews)
    $preheader = $statusLabel;
    foreach (explode("\n", $plainBody) as $preheaderLine) {
        if (strpos($preheaderLine, '] Success:') !== false || strpos($preheaderLine, '] Errors:') !== false) {
            $preheader = trim(preg_replace('/^\[\d{2}:\d{2}:\d{2}\]\s*/', '', $preheaderLine));
            break;
        }
    }

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
    $host       = $smtpConfig['host'];
    $port       = (int) $smtpConfig['port'];
    $user       = $smtpConfig['user'];
    $password   = $smtpConfig['password'];
    $encryption = $smtpConfig['encryption'] ?? 'tls';

    // Connect
    $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 30);
    if (!$socket) {
        outputError('SMTP connect failed: ' . $errstr);
        return false;
    }

    // Send a command, read one response line, return the numeric code
    $sendCmd = function (string $cmd) use ($socket): int {
        fwrite($socket, $cmd . "\r\n");

        return (int) substr(fgets($socket, 512), 0, 3);
    };

    // Read one response line, return the numeric code
    $readCode = function () use ($socket): int {
        return (int) substr(fgets($socket, 512), 0, 3);
    };

    // Consume remaining lines of a multi-line response, return final code
    $drainMulti = function () use ($socket): int {
        $code = 0;
        while (($line = fgets($socket, 512)) !== false) {
            $code = (int) substr($line, 0, 3);
            if (substr($line, 3, 1) !== '-') {
                break;
            }
        }

        return $code;
    };

    // Read greeting
    $readCode();

    // EHLO
    $sendCmd('EHLO ' . gethostname());
    $drainMulti();

    // STARTTLS upgrade
    if ($encryption === 'tls') {
        $code = $sendCmd('STARTTLS');
        if ($code !== 220) {
            outputError('SMTP STARTTLS failed (' . $code . ')');
            fclose($socket);
            return false;
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $sendCmd('EHLO ' . gethostname());
        $drainMulti();
    }

    // AUTH LOGIN
    $sendCmd('AUTH LOGIN');
    $sendCmd(base64_encode($user));
    $code = $sendCmd(base64_encode($password));
    if ($code !== 235) {
        outputError('SMTP authentication failed (' . $code . ') -- check user/password');
        fclose($socket);
        return false;
    }

    // Envelope
    $code = $sendCmd('MAIL FROM:<' . $from . '>');
    if ($code !== 250) {
        outputError('SMTP MAIL FROM rejected (' . $code . ')');
        fclose($socket);
        return false;
    }
    $code = $sendCmd('RCPT TO:<' . $to . '>');
    if ($code !== 250) {
        outputError('SMTP RCPT TO rejected (' . $code . ')');
        fclose($socket);
        return false;
    }
    $code = $sendCmd('DATA');
    if ($code !== 354) {
        outputError('SMTP DATA rejected (' . $code . ')');
        fclose($socket);
        return false;
    }

    // Build multipart/alternative message (plain + HTML)
    $boundary = 'bp_' . md5(uniqid('', true));
    $htmlBody = wrapEmailHtml($body, outputHasErrors());

    // Prepend a plain-text preheader so notification previews (Thunderbird, etc.)
    // show the summary instead of the first dashes separator line
    $plainPreheader = '';
    foreach (explode("\n", $body) as $preheaderLine) {
        if (strpos($preheaderLine, '] Success:') !== false || strpos($preheaderLine, '] Errors:') !== false) {
            $plainPreheader = trim(preg_replace('/^\[\d{2}:\d{2}:\d{2}\]\s*/', '', $preheaderLine)) . "\n\n";
            break;
        }
    }
    $plainBody = $plainPreheader . $body;

    // Dot-stuff: lines beginning with '.' must be escaped as '..' per RFC 5321
    $dotStuff = function (string $text): string {
        return preg_replace('/^\.$/m', '..', preg_replace('/^\./m', '..', $text));
    };

    $date    = date('r');
    $message = 'Date: ' . $date . "\r\n"
        . 'From: ' . $from . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . $subject . "\r\n"
        . 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n"
        . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
        . "\r\n"
        . $dotStuff($plainBody) . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
        . "\r\n"
        . $dotStuff($htmlBody) . "\r\n"
        . '--' . $boundary . '--';
    fwrite($socket, $message . "\r\n.\r\n");
    $code = $readCode();
    if ($code !== 250) {
        outputError('SMTP message rejected (' . $code . ')');
        fclose($socket);
        return false;
    }

    fwrite($socket, 'QUIT' . "\r\n");
    fclose($socket);

    return true;
}
