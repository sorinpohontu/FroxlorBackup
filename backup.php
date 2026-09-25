<?php

/**
 * @package     Froxlor Backup
 *
 * @subpackage  Orchestrator
 *
 * @author      Sorin Pohontu <sorin@frontline.ro>
 * @copyright   2026 Frontline softworks <https://www.frontline.ro>
 * @license     https://opensource.org/licenses/BSD-3-Clause
 *
 * @since       2026.09.24
 */

// Help / Usage
if (in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
    fwrite(STDOUT, implode(PHP_EOL, [
        'Usage: php backup.php [options]',
        '',
        'Options:',
        '  -h, --help       Show this help and exit',
        '  --check-install  Check local config, PHP capabilities, and tools without changes',
        '  --dry-run        Preview backup actions without changing files or sending a report',
        '  --test-email     Send a test report; requires --dry-run',
        '  --verbose        Show detailed backup progress',
    ]) . PHP_EOL);
    exit(0);
}

// Ensure POSIX effective-UID support is available
umask(0077);
if (!function_exists('posix_geteuid')) {
    fwrite(STDERR, 'POSIX effective-UID support is required.' . PHP_EOL);
    exit(1);
}

// Validate inputs
$trustedInput = function (string $path, bool $secret): bool {
    if (is_link($path) || !is_file($path)) {
        return false;
    }
    $permissions = fileperms($path);
    if ($permissions === false || ($permissions & ($secret ? 0077 : 0022)) !== 0) {
        return false;
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && fileowner($path) !== 0) {
        return false;
    }
    $parent = dirname($path);
    while ($parent !== dirname($parent)) {
        if (
            is_link($parent) || !is_dir($parent) || (fileperms($parent) & 0022) !== 0
            || (posix_geteuid() === 0 && fileowner($parent) !== 0)
        ) {
            return false;
        }
        $parent = dirname($parent);
    }

    return true;
};

// Validate trusted input files
$localConfig = __DIR__ . '/config.local.php';
$trustedFiles = [__DIR__ . '/config.php', __DIR__ . '/lib/BackupHelpers.php', __DIR__ . '/lib/BackupSteps.php'];
foreach ($trustedFiles as $path) {
    if (!$trustedInput($path, false)) {
        fwrite(STDERR, 'Cannot safely load backup code: ' . $path . PHP_EOL
            . 'Check that this backup file is regular, root-owned when running as root, and not group/other writable.'
            . PHP_EOL);
        exit(1);
    }
}
if (file_exists($localConfig) || is_link($localConfig)) {
    if (!$trustedInput($localConfig, true)) {
        fwrite(STDERR, 'Cannot safely load backup configuration: ' . $localConfig . PHP_EOL
            . 'Check that it is a regular file, root-owned when running as root, with mode 0600 or stricter.'
            . PHP_EOL);
        exit(1);
    }
}

// Load configuration files with overrides
$defaults = require __DIR__ . '/config.php';
$overrides = file_exists($localConfig) ? require $localConfig : [];
if (!is_array($defaults) || !is_array($overrides)) {
    fwrite(STDERR, 'Configuration files must return arrays.' . PHP_EOL);
    exit(1);
}
$config = array_replace_recursive($defaults, $overrides);

// Include necessary library files
require_once __DIR__ . '/lib/BackupHelpers.php';
require_once __DIR__ . '/lib/BackupSteps.php';

// Parse command-line options
$verbose      = in_array('--verbose', $argv ?? [], true);
$dryRun       = in_array('--dry-run', $argv ?? [], true);
$testEmail    = in_array('--test-email', $argv ?? [], true);
$checkInstall = in_array('--check-install', $argv ?? [], true);

// Resolve and set the timezone
$resolvedTimezone = is_string($config['timezone'] ?? null) ? resolveTimezone($config['timezone']) : null;
if ($resolvedTimezone !== null) {
    date_default_timezone_set($resolvedTimezone);
}

outputInit();
summaryInit();

if ($testEmail && !$dryRun) {
    outputError('--test-email requires --dry-run');
    exit(1);
}
if ($testEmail && $checkInstall) {
    outputError('--test-email cannot be combined with --check-install');
    exit(1);
}

if ($checkInstall) {
    outputSection('Installation Check');
}
$configValid = validateConfig($config, $testEmail);
$runtimeValid = ensureSecureRuntimeDirectory(__DIR__, false);
if ($checkInstall) {
    if ($configValid && $runtimeValid) {
        foreach (installationCheckLines($config, $testEmail) as $line) {
            output('[OK] ' . $line);
        }
        output('[SKIP] Remote connectivity and credentials');
        output('Installation check passed.');
    } else {
        output('Installation check failed. Address the errors above, then rerun --check-install.');
    }
    exit(outputHasErrors() ? 1 : 0);
}
if (!$configValid || !$runtimeValid) {
    output('Backup stopped because the installation check failed. Address the errors above, then rerun --check-install.');
    exit(1);
}

// Acquire lock to prevent concurrent backups
$lockFp = null;
if (!$dryRun) {
    $lockFp = acquireLock(__DIR__ . '/froxlor-backup.lock');
    if (!$lockFp) {
        if (!outputHasErrors()) {
            outputError(
                'Cannot acquire the backup lock.',
                'Check for an active backup process and verify this installation can create its lock file.'
            );
        }
        exit(1);
    }
// End of lock acquisition section
}

// Start of backup process
$totalStart = microtime(true);
$today = date('Y-m-d');
$hostname = trim(gethostname()) ?: 'localhost';
$complete = ['customers' => false, 'system' => false];

outputSection('Backup Log');

// Notify if running in dry-run mode
if ($dryRun) {
    output('DRY RUN -- no files will be changed');
}
output('Backup started on ' . $hostname . ' at ' . date('Y-m-d H:i:s'));

$froxlorSettingsError = false;
try {
    // Froxlor settings and database
    $db = null;
    $dbRoot = null;
    if ($config['customers']['enabled'] || $config['control_panel']['enabled']) {
        $froxlor = loadFroxlorSettings($config['control_panel']['path']);
        if ($froxlor === null) {
            $froxlorSettingsError = true;
            throw new \RuntimeException('Froxlor settings are unavailable');
        }
        $sql = $froxlor['sql'];
        $sql_root = $froxlor['sql_root'];
        if ($config['customers']['enabled']) {
            $db = dbConnect($sql['host'], $sql['db'], $sql['user'], $sql['password']);
        }
        $needsRoot = ($config['customers']['enabled'] && $config['customers']['databases'])
            || $config['control_panel']['enabled'];
        if (!$dryRun && $needsRoot) {
            $dbRoot = dbConnect($sql_root[0]['host'], 'mysql', $sql_root[0]['user'], $sql_root[0]['password']);
        }
    }

    if ($config['customers']['enabled']) {
        $stepStart = microtime(true);
        list($complete['customers'], $stepSize) = backupCustomers(
            $config,
            $db,
            $dbRoot,
            $sql,
            $sql_root,
            $today,
            $verbose,
            $dryRun
        );
        summaryTime('Customers', $stepStart, $stepSize);
    }

    if ($config['system']['enabled'] || $config['control_panel']['enabled']) {
        $stepStart = microtime(true);
        $baseDir = $config['system']['dir'];
        $finalDir = $config['keep_local_days'] > 0 ? $baseDir . '/' . $today : $baseDir;
        $cleanBefore = $config['clean_before_backup'] && $config['keep_local_days'] === 0;
        $workDir = $finalDir;
        $groupOk = true;
        if ($cleanBefore && !$dryRun) {
            $workDir = prepareBackupReplacement($finalDir);
            $groupOk = $workDir !== '';
        }
        if ($groupOk && $config['system']['enabled']) {
            $groupOk = backupSystem($config, $workDir, $dryRun) && $groupOk;
        }
        if ($groupOk && $config['control_panel']['enabled']) {
            $groupOk = backupControlPanel($config, $workDir, $dbRoot, $sql, $sql_root, $dryRun) && $groupOk;
        }
        if ($cleanBefore && !$dryRun) {
            if ($groupOk) {
                $groupOk = publishBackupReplacement($workDir, $finalDir);
            } else {
                outputError('System replacement not published; inspect ' . $workDir);
            }
        }
        $complete['system'] = $groupOk;
        $groupSize = $groupOk && !$dryRun ? dirSize($finalDir) : 0;
        if ($groupSize === null) {
            $groupOk = false;
            $complete['system'] = false;
            $groupSize = 0;
        }
        $groupLabel = $config['system']['enabled'] && $config['control_panel']['enabled'] ? 'System / Control Panel' : ($config['system']['enabled'] ? 'System' : 'Control Panel');
        summaryTime($groupLabel, $stepStart, $groupSize);
    }

    $syncOk = true;
    if ($config['rsync']['enabled']) {
        $stepStart = microtime(true);
        $syncOk = stepSyncRsync($config, $today, $dryRun, $complete) && $syncOk;
        summaryTime('Rsync', $stepStart);
    }
    if ($config['s3']['enabled']) {
        $stepStart = microtime(true);
        $syncOk = stepSyncS3($config, $today, $dryRun, $complete) && $syncOk;
        summaryTime('S3', $stepStart);
    }

    if (!$dryRun && $syncOk && $config['keep_local_days'] > 0) {
        if ($config['customers']['enabled'] && $complete['customers']) {
            cleanCustomerBackups($config['customers']['dir'], $today, $config['keep_local_days']);
        }
        if (($config['system']['enabled'] || $config['control_panel']['enabled']) && $complete['system']) {
            cleanLocalBackups($config['system']['dir'], $today, $config['keep_local_days']);
        }
    }
} catch (\Throwable $error) {
    if (!$froxlorSettingsError || !outputHasErrors()) {
        outputError('Backup interrupted: ' . $error->getMessage());
    }
} finally {
    if ($lockFp !== null) {
        releaseLock($lockFp);
    }
}

// Summary
outputSeparator();
$summaryStr = summaryGet();
if ($summaryStr !== '') {
    output((outputHasErrors() ? 'Errors: ' : 'Success: ') . $summaryStr);
}
$totalSize = summaryTotalSize();
output('Completed in ' . formatDuration(microtime(true) - $totalStart)
    . ($totalSize > 0 ? ' (' . formatSize($totalSize) . ')' : ''));
outputSeparator();
$timings = summaryTimingGet();
if ($timings !== '') {
    outputLines($timings);
}
outputSeparator();
output(outputHasErrors() ? 'Completed with errors.' : 'Backup finished successfully at ' . date('Y-m-d H:i:s'));

// Email report
if (($config['email']['enabled'] && !$dryRun) || $testEmail) {
    $subject = str_replace(['{hostname}', '{date}'], [$hostname, $today], $config['email']['subject']);
    if ($testEmail) {
        $subject = '[TEST] ' . $subject;
    }
    if (outputHasErrors()) {
        $subject .= ' [Error]';
    }
    try {
        smtpSend($config['email']['smtp'], $config['email']['from'], $config['email']['to'], $subject, outputGet());
    } catch (\Throwable $error) {
        outputError('Email report failed: ' . $error->getMessage());
    }
}

// Exit
exit(outputHasErrors() ? 1 : 0);
