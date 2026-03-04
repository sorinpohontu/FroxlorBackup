<?php

/**
 * @package     Froxlor Backup
 *
 * @subpackage  Steps
 *
 * @author      Sorin Pohontu <sorin@frontline.ro>
 * @copyright   2026 Frontline softworks <https://www.frontline.ro>
 * @license     https://opensource.org/licenses/BSD-3-Clause
 *
 * @since       2026.03.04
 */

/**
 * Back up all active Froxlor customers: vhosts, databases, mailboxes, logs
 *
 * @param array   $config  Merged config array
 * @param \PDO    $db      Froxlor database connection
 * @param \PDO    $dbRoot  Root MySQL connection (for mysqldump + grants)
 * @param array   $sql     Froxlor $sql credentials array
 * @param array   $sqlRoot Froxlor $sql_root credentials array
 * @param string  $today   Date string YYYY-MM-DD
 * @param boolean $verbose Show per-file detail
 * @param boolean $dryRun  Simulate without making changes
 *
 * @return void
 */
function backupCustomers(
    array $config,
    \PDO $db,
    \PDO $dbRoot,
    array $sql,
    array $sqlRoot,
    string $today,
    bool $verbose,
    bool $dryRun
): void {
    outputSection('Customers Backup');

    $method          = $config['archive_method'];
    $backupBaseDir   = $config['customers']['dir'];
    $keepLocalDays   = (int) $config['keep_local_days'];
    $cleanBefore     = (bool) $config['clean_before_backup'] && $keepLocalDays === 0;
    $vhostsCfg       = $config['customers']['vhosts'];
    $logfilesDir     = getSetting($db, 'logfiles_directory');
    $useGoaccess     = $vhostsCfg['goaccess'] && getSetting($db, 'traffictool') === 'goaccess';

    $customers = dbQuery($db, 'SELECT customerid, loginname, documentroot, firstname, name'
        . ' FROM ' . TABLE_PANEL_CUSTOMERS
        . ' WHERE deactivated = 0'
        . ' ORDER BY loginname');

    if (empty($customers)) {
        output('No active customers found.');

        return;
    }

    foreach ($customers as $customer) {
        $start      = microtime(true);
        $loginname  = $customer['loginname'];
        $fullname   = trim($customer['firstname'] . ' ' . $customer['name']);
        $customerBaseDir = $backupBaseDir . '/' . $loginname;
        $backupDir       = $keepLocalDays > 0 ? $customerBaseDir . '/' . $today : $customerBaseDir;

        output('Backing up [' . $loginname . '] (' . $fullname . ') ...');

        if ($dryRun) {
            $action = $cleanBefore ? 'would recreate' : 'would create';
            output('  [dry-run] ' . $action . ': ' . $backupDir);
            summaryAdd('customers');
            outputDone($start);
            continue;
        }

        if ($cleanBefore && is_dir($backupDir)) {
            shell_exec('rm -rf ' . escapeshellarg($backupDir));
        }

        ensureDir($backupDir);

        // Vhosts
        if ($vhostsCfg['enabled']) {
            $backupDirVhosts = $vhostsCfg['separate_archives'] ? $backupDir . '/vhosts' : $backupDir;
            ensureDir($backupDirVhosts);

            if ($vhostsCfg['separate_archives']) {
                $domains = dbQuery(
                    $db,
                    'SELECT d.domain, d.documentroot,'
                    . ' CASE d.parentdomainid'
                    . '   WHEN 0 THEN NULL'
                    . '   ELSE (SELECT domain FROM ' . TABLE_PANEL_DOMAINS . ' WHERE id = d.parentdomainid)'
                    . ' END as parent_domain'
                    . ' FROM ' . TABLE_PANEL_DOMAINS . ' d'
                    . ' WHERE d.customerid = ' . (int) $customer['customerid']
                    . ' AND d.aliasdomain IS NULL'
                    . ' ORDER BY parent_domain, d.domain'
                );

                $vhostCount = 0;
                foreach ($domains as $domain) {
                    // Skip email-only accounts (no document root on disk)
                    if (!is_dir($domain['documentroot'])) {
                        continue;
                    }

                    // Skip excluded domains
                    if (in_array($domain['domain'], $vhostsCfg['exclude_domains'])) {
                        if ($verbose) {
                            output('  Skipping: ' . $domain['domain'] . ' (excluded)');
                        }
                        continue;
                    }

                    // Group subdomains under their parent domain directory
                    $domainDir = $domain['parent_domain'] !== null ? $backupDirVhosts . '/' . $domain['parent_domain'] : $backupDirVhosts . '/' . $domain['domain'];
                    ensureDir($domainDir);

                    if ($verbose) {
                        output('  VHost: ' . $domain['domain']);
                    }

                    archiveDirectory(
                        $domain['documentroot'],
                        $domainDir . '/' . $domain['domain'],
                        $method
                    );
                    $vhostCount++;
                }

                summaryAdd('vhosts', $vhostCount);
            } else {
                // Single archive for all vhosts
                archiveDirectory(
                    $customer['documentroot'],
                    $backupDirVhosts . '/vhosts',
                    $method
                );
                summaryAdd('vhosts');
            }

            // GoAccess database
            if ($useGoaccess) {
                $goaccessDir = $customer['documentroot'] . '/goaccess';
                if (is_dir($goaccessDir)) {
                    if ($verbose) {
                        output('  GoAccess: archiving');
                    }
                    archiveDirectory($goaccessDir, $backupDir . '/vhosts-goaccess', $method);
                }
            }
        }

        // Logs
        if ($config['customers']['logs'] && $logfilesDir) {
            if ($verbose) {
                output('  Logs: archiving');
            }
            archiveGlob($loginname . '*.log', $logfilesDir, $backupDir . '/vhosts-log', $method);
        }

        // Mailboxes
        if ($config['customers']['mails']) {
            $mailboxes = dbQuery(
                $db,
                'SELECT d.domain, u.username, u.homedir, u.maildir'
                . ' FROM ' . TABLE_MAIL_USERS . ' u, ' . TABLE_PANEL_DOMAINS . ' d'
                . ' WHERE u.domainid = d.id'
                . ' AND u.customerid = ' . (int) $customer['customerid']
                . ' ORDER BY d.domain, u.username'
            );

            if (!empty($mailboxes)) {
                $mailCount = 0;
                foreach ($mailboxes as $mailbox) {
                    $mailDir = $backupDir . '/mail/' . $mailbox['domain'];
                    ensureDir($mailDir);

                    if ($verbose) {
                        output('  Mail: ' . $mailbox['username'] . '@' . $mailbox['domain']);
                    }

                    archiveDirectory(
                        $mailbox['homedir'] . $mailbox['maildir'],
                        $mailDir . '/' . $mailbox['username'],
                        $method
                    );
                    $mailCount++;
                }
                summaryAdd('mailboxes', $mailCount);
            }
        }

        // Databases
        if ($config['customers']['databases']) {
            $databases = dbQuery(
                $db,
                'SELECT databasename FROM ' . TABLE_PANEL_DATABASES
                . ' WHERE customerid = ' . (int) $customer['customerid']
            );

            if (!empty($databases)) {
                $dbDir    = $backupDir . '/databases';
                $dbCount  = 0;
                ensureDir($dbDir);

                foreach ($databases as $database) {
                    if ($verbose) {
                        output('  DB: ' . $database['databasename']);
                    }

                    dumpDatabase(
                        $database['databasename'],
                        $dbDir,
                        $dbRoot,
                        $database['databasename'],
                        $sql['host'],
                        $sqlRoot[0]['host'],
                        $method
                    );
                    $dbCount++;
                }
                summaryAdd('databases', $dbCount);
            }
        }

        // Local retention cleanup for this customer
        if ($keepLocalDays > 0) {
            cleanLocalBackups($customerBaseDir, $today, $keepLocalDays);
        }

        summaryAdd('customers');
        outputDone($start);
    }
}

/**
 * Back up system config files from the file list
 *
 * @param array   $config Merged config array
 * @param boolean $dryRun Simulate without making changes
 *
 * @return void
 */
function backupSystem(array $config, bool $dryRun): void
{
    outputSection('System Backup');

    $start    = microtime(true);
    $fileList = $config['system']['file_list'] ?? '';

    if (empty($fileList) || !file_exists($fileList)) {
        output('Skipping — file list not available.');

        return;
    }

    // Merge with local overrides if present
    $localFileList = dirname($fileList) . '/' . basename($fileList) . '.local';

    if (file_exists($localFileList)) {
        $merged   = sys_get_temp_dir() . '/backup-system-file-list-' . getmypid();
        $contents = file_get_contents($fileList) . "\n" . file_get_contents($localFileList);
        file_put_contents($merged, $contents);
        $fileList = $merged;
        output('Merging local file list: ' . basename($localFileList));
    }

    $method        = $config['archive_method'];
    $keepLocalDays = (int) $config['keep_local_days'];
    $cleanBefore   = (bool) $config['clean_before_backup'] && $keepLocalDays === 0;
    $baseDir       = $config['system']['dir'];
    $destDir       = $keepLocalDays > 0 ? $baseDir . '/' . date('Y-m-d') : $baseDir;

    output('Archiving system config files ...');

    if ($dryRun) {
        $action = $cleanBefore ? '[dry-run] would recreate' : '[dry-run] would archive';
        output($action . ': ' . $destDir . '/system-config');
        outputDone($start);

        return;
    }

    if ($cleanBefore && is_dir($destDir)) {
        shell_exec('rm -rf ' . escapeshellarg($destDir));
    }

    ensureDir($destDir);
    $ok = archiveFileList($fileList, $destDir . '/system-config', $method);

    if (isset($merged)) {
        unlink($merged);
    }

    if (!$ok) {
        outputError('System backup archive was not created.');
    }

    // Local retention cleanup
    if ($keepLocalDays > 0) {
        $removed = cleanLocalBackups($baseDir, date('Y-m-d'), $keepLocalDays);
        if ($removed > 0) {
            output('Cleanup: removed ' . $removed . ' old system backup(s)');
        }
    }

    outputDone($start);
}

/**
 * Back up the Froxlor control panel files and database
 *
 * @param array   $config  Merged config array
 * @param \PDO    $db      Froxlor database connection
 * @param \PDO    $dbRoot  Root MySQL connection
 * @param array   $sql     Froxlor $sql credentials array
 * @param array   $sqlRoot Froxlor $sql_root credentials array
 * @param boolean $dryRun  Simulate without making changes
 *
 * @return void
 */
function backupControlPanel(
    array $config,
    \PDO $db,
    \PDO $dbRoot,
    array $sql,
    array $sqlRoot,
    bool $dryRun
): void {
    outputSection('Control Panel Backup');

    $start         = microtime(true);
    $method        = $config['archive_method'];
    $keepLocalDays = (int) $config['keep_local_days'];
    $baseDir       = $config['system']['dir'];
    $destDir       = $keepLocalDays > 0 ? $baseDir . '/' . date('Y-m-d') : $baseDir;
    $panelDir      = $config['control_panel']['path'];

    if ($dryRun) {
        output('[dry-run] would archive: ' . $panelDir . ' -> ' . $destDir . '/control-panel-files');
        output('[dry-run] would dump DB: ' . $sql['db'] . ' -> ' . $destDir . '/control-panel-database');
        outputDone($start);

        return;
    }

    // Clean only if system backup is disabled (otherwise backupSystem already handled it)
    $cleanBefore = (bool) $config['clean_before_backup'] && $keepLocalDays === 0;
    if ($cleanBefore && !$config['system']['enabled'] && is_dir($destDir)) {
        shell_exec('rm -rf ' . escapeshellarg($destDir));
    }

    ensureDir($destDir);

    // Files
    output('Archiving control panel files ...');
    $ok = archiveDirectory($panelDir, $destDir . '/control-panel-files', $method);

    if (!$ok) {
        outputError('Control panel files archive was not created.');
    }

    // Database
    output('Dumping control panel database ...');
    dumpDatabase(
        $sql['db'],
        $destDir,
        $dbRoot,
        $sql['user'],
        $sql['host'],
        $sqlRoot[0]['host'],
        $method,
        'control-panel-database'
    );

    outputDone($start);
}

/**
 * Sync backups to a remote host via rsync, with optional old-backup cleanup
 *
 * @param array   $config Merged config array
 * @param string  $today  Date string YYYY-MM-DD
 * @param boolean $dryRun Simulate without making changes
 *
 * @return void
 */
function stepSyncRsync(array $config, string $today, bool $dryRun): void
{
    outputSection('Sync: Rsync');

    $start    = microtime(true);
    $cfg      = $config['rsync'];
    $host     = $cfg['ssh_host'];
    $params   = $cfg['bin_params'];
    $hostname = trim(gethostname()) ?: 'localhost';

    $pathCustomers = $cfg['path_customers'] !== '' ? $cfg['path_customers'] : 'backups/' . $hostname . '/clients';
    $pathSystem = $cfg['path_system'] !== '' ? $cfg['path_system'] : 'backups/' . $hostname . '/system';

    if ($dryRun) {
        output('[dry-run] would rsync to ' . $host . ':' . $pathCustomers . '/' . $today);
        output('[dry-run] would rsync to ' . $host . ':' . $pathSystem . '/' . $today);
        outputDone($start);

        return;
    }

    $deleted = 0;

    // Customers
    if ($config['customers']['enabled']) {
        if ($cfg['delete_strategy'] === 'before') {
            $old      = deletableFiles($today, rsyncList($host, $pathCustomers), $cfg['keep_days']);
            $deleted += count($old);
            rsyncDeleteFiles($host, $pathCustomers, $old);
        }

        output('Syncing customers ...');
        doRsync($host, $config['customers']['dir'], $pathCustomers . '/' . $today, $params);

        if ($cfg['delete_strategy'] === 'after') {
            $old      = deletableFiles($today, rsyncList($host, $pathCustomers), $cfg['keep_days']);
            $deleted += count($old);
            rsyncDeleteFiles($host, $pathCustomers, $old);
        }
    }

    // System
    if ($config['system']['enabled']) {
        if ($cfg['delete_strategy'] === 'before') {
            $old      = deletableFiles($today, rsyncList($host, $pathSystem), $cfg['keep_days']);
            $deleted += count($old);
            rsyncDeleteFiles($host, $pathSystem, $old);
        }

        output('Syncing system ...');
        doRsync($host, $config['system']['dir'], $pathSystem . '/' . $today, $params);

        if ($cfg['delete_strategy'] === 'after') {
            $old      = deletableFiles($today, rsyncList($host, $pathSystem), $cfg['keep_days']);
            $deleted += count($old);
            rsyncDeleteFiles($host, $pathSystem, $old);
        }
    }

    if ($deleted > 0) {
        output('Cleanup: removed ' . $deleted . ' old backup(s) (> ' . $cfg['keep_days'] . ' days)');
    }

    summaryAdd('targets', $config['customers']['enabled'] + $config['system']['enabled']);
    outputDone($start);
}

/**
 * Sync backups to S3 via s3cmd, with optional old-backup cleanup
 *
 * @param array   $config Merged config array
 * @param string  $today  Date string YYYY-MM-DD
 * @param boolean $dryRun Simulate without making changes
 *
 * @return void
 */
function stepSyncS3(array $config, string $today, bool $dryRun): void
{
    outputSection('Sync: S3');

    $start    = microtime(true);
    $cfg      = $config['s3'];
    $params   = $cfg['bin_params'];
    $hostname = trim(gethostname()) ?: 'localhost';

    $pathCustomers = $cfg['path_customers'] !== '' ? $cfg['path_customers'] : $cfg['bucket'] . '/' . $hostname . '/clients';
    $pathSystem = $cfg['path_system'] !== '' ? $cfg['path_system'] : $cfg['bucket'] . '/' . $hostname . '/system';

    if ($dryRun) {
        output('[dry-run] would s3 sync to ' . $pathCustomers . '/' . $today);
        output('[dry-run] would s3 sync to ' . $pathSystem . '/' . $today);
        outputDone($start);

        return;
    }

    $deleted = 0;

    // Customers
    if ($config['customers']['enabled']) {
        if ($cfg['delete_strategy'] === 'before') {
            $old      = deletableFiles($today, s3List($pathCustomers), $cfg['keep_days']);
            $deleted += count($old);
            s3DeleteFiles($old);
        }

        output('Syncing customers ...');
        doS3Sync($config['customers']['dir'], $pathCustomers . '/' . $today, $params);

        if ($cfg['delete_strategy'] === 'after') {
            $old      = deletableFiles($today, s3List($pathCustomers), $cfg['keep_days']);
            $deleted += count($old);
            s3DeleteFiles($old);
        }
    }

    // System
    if ($config['system']['enabled']) {
        if ($cfg['delete_strategy'] === 'before') {
            $old      = deletableFiles($today, s3List($pathSystem), $cfg['keep_days']);
            $deleted += count($old);
            s3DeleteFiles($old);
        }

        output('Syncing system ...');
        doS3Sync($config['system']['dir'], $pathSystem . '/' . $today, $params);

        if ($cfg['delete_strategy'] === 'after') {
            $old      = deletableFiles($today, s3List($pathSystem), $cfg['keep_days']);
            $deleted += count($old);
            s3DeleteFiles($old);
        }
    }

    if ($deleted > 0) {
        output('Cleanup: removed ' . $deleted . ' old backup(s) (> ' . $cfg['keep_days'] . ' days)');
    }

    summaryAdd('targets', $config['customers']['enabled'] + $config['system']['enabled']);
    outputDone($start);
}
