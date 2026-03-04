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
 * @since       2026.03.04
 */

namespace frontline;

// Load config (defaults merged with local overrides)
$config = array_replace_recursive(
    require __DIR__ . '/config.php',
    file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : []
);

date_default_timezone_set($config['timezone']);

// Load helpers and step functions
require_once __DIR__ . '/lib/BackupHelpers.php';
require_once __DIR__ . '/lib/BackupSteps.php';

// Parse CLI arguments
$verbose   = in_array('--verbose', $argv ?? []);
$dryRun    = in_array('--dry-run', $argv ?? []);
$testEmail = in_array('--test-email', $argv ?? []);

// Acquire lock — prevent simultaneous runs
$lockFile = sys_get_temp_dir() . '/froxlor-backup.lock';
$lockFp   = acquireLock($lockFile);
if (!$lockFp) {
    echo 'Another backup is already running. Exiting.' . PHP_EOL;
    exit(1);
}

// Initialize output buffer and summary counters
outputInit();
summaryInit();

$totalStart = microtime(true);
$today      = date('Y-m-d');
$hostname   = trim(gethostname()) ?: 'localhost';
$db         = null;
$dbRoot     = null;

outputSection('Backup Log');

if ($dryRun) {
    output('DRY RUN -- no changes will be made');
}
output('Backup started on ' . $hostname . ' at ' . date('Y-m-d H:i:s'));

// --test-email requires --dry-run (real backups must not run during an email test)
if ($testEmail && !$dryRun) {
    echo '--test-email requires --dry-run. Run: php backup.php --dry-run --test-email' . PHP_EOL;
    releaseLock($lockFp, $lockFile);
    exit(1);
}

// --test-email: validate SMTP config before doing any work
if ($testEmail) {
    $smtpCfg = $config['email']['smtp'];
    if (
        empty($smtpCfg['host']) || empty($smtpCfg['user']) || empty($smtpCfg['password'])
        || empty($config['email']['from']) || empty($config['email']['to'])
    ) {
        echo '--test-email requires SMTP config (host, user, password, from, to) in config.local.php.' . PHP_EOL;
        releaseLock($lockFp, $lockFile);
        exit(1);
    }
}

// Validate config early — fail fast with clear messages
if (!validateConfig($config)) {
    output('Config validation failed. Aborting.');
    releaseLock($lockFp, $lockFile);
    exit(1);
}

// Connect to Froxlor database if any backup step needs it
if ($config['customers']['enabled'] || $config['control_panel']['enabled']) {
    require_once $config['control_panel']['path'] . '/lib/userdata.inc.php';
    require_once $config['control_panel']['path'] . '/lib/tables.inc.php';

    $db     = dbConnect($sql['host'], $sql['db'], $sql['user'], $sql['password']);
    $dbRoot = dbConnect($sql_root[0]['host'], 'mysql', $sql_root[0]['user'], $sql_root[0]['password']);
}

// Run backup steps
if ($config['customers']['enabled']) {
    $stepStart = microtime(true);
    backupCustomers($config, $db, $dbRoot, $sql, $sql_root, $today, $verbose, $dryRun);
    summaryTime('Customers', $stepStart);
}
if ($config['system']['enabled']) {
    $stepStart = microtime(true);
    backupSystem($config, $dryRun);
    summaryTime('System', $stepStart);
}
if ($config['control_panel']['enabled']) {
    $stepStart = microtime(true);
    backupControlPanel($config, $db, $dbRoot, $sql, $sql_root, $dryRun);
    summaryTime('Control Panel', $stepStart);
}

// Run sync steps
if ($config['rsync']['enabled']) {
    $stepStart = microtime(true);
    stepSyncRsync($config, $today, $dryRun);
    summaryTime('Rsync', $stepStart);
}
if ($config['s3']['enabled']) {
    $stepStart = microtime(true);
    stepSyncS3($config, $today, $dryRun);
    summaryTime('S3', $stepStart);
}

outputSeparator();

// Summary
$totalElapsed = number_format(microtime(true) - $totalStart, 2);
$summaryStr  = summaryGet();
$summaryVerb = outputHasErrors() ? 'Errors:' : 'Success:';
output($summaryVerb . ' ' . ($summaryStr !== '' ? $summaryStr . ' ' : '') . 'completed in ' . $totalElapsed . 's');

outputSeparator();

if (summaryTimingGet() !== '') {
    output(summaryTimingGet());
}

outputSeparator();

if (outputHasErrors()) {
    output('Completed with errors.');
} else {
    output('Backup finished successfully at ' . date('Y-m-d H:i:s'));
}

// Send email report (always sent when --test-email, otherwise only if email.enabled)
if ($config['email']['enabled'] || $testEmail) {
    $subject = str_replace(
        ['{hostname}', '{date}'],
        [$hostname, $today],
        $config['email']['subject']
    );
    if ($testEmail) {
        $subject = '[TEST] ' . $subject;
    }
    if (outputHasErrors()) {
        $subject .= ' [Error]';
    }
    smtpSend(
        $config['email']['smtp'],
        $config['email']['from'],
        $config['email']['to'],
        $subject,
        outputGet()
    );
}

// Release lock and exit with appropriate code
releaseLock($lockFp, $lockFile);
exit(outputHasErrors() ? 1 : 0);
