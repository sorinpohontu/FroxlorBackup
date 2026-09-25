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
 * @since       2026.09.24
 */

/**
 * Resolve exclusions for every domain root inside a combined customer archive
 *
 * @param string   $customerRoot   Combined archive root
 * @param array    $domains        Froxlor domain rows
 * @param string[] $excludeFiles   Relative vhost exclusions
 * @param string[] $excludeDomains Domain names omitted from the archive
 *
 * @return array|null Native relative exclusions, or null on unsafe overlap
 */
function combinedVhostExclusions(
    string $customerRoot,
    array $domains,
    array $excludeFiles,
    array $excludeDomains
): ?array {
    $root = realpath($customerRoot);
    if ($root === false) {
        outputError('Combined vhost root is unavailable: ' . $customerRoot);
        return null;
    }
    $exclusions = array_fill_keys($excludeFiles, true);
    foreach ($domains as $domain) {
        $documentRoot = realpath($domain['documentroot']);
        if ($documentRoot === false || !is_dir($documentRoot)) {
            continue;
        }
        if ($documentRoot !== $root && strpos($documentRoot . '/', rtrim($root, '/') . '/') !== 0) {
            continue;
        }
        $relative = ltrim(substr($documentRoot, strlen($root)), '/');
        if (in_array($domain['domain'], $excludeDomains, true)) {
            if ($relative === '' || !isValidExcludePath($relative)) {
                outputError('Cannot exclude shared or unsafe combined domain root: ' . $domain['domain']);
                return null;
            }
            $exclusions[$relative] = true;
            continue;
        }
        foreach ($excludeFiles as $excludeFile) {
            $candidate = $relative === '' ? $excludeFile : $relative . '/' . $excludeFile;
            if (!isValidExcludePath($candidate)) {
                outputError('Unsafe combined vhost exclusion: ' . $candidate);
                return null;
            }
            $exclusions[$candidate] = true;
        }
    }

    return array_keys($exclusions);
}

/**
 * Detect unpublished customer backup replacements in the sync source
 *
 * @param string $baseDir Customer backup root
 *
 * @return boolean True when a replacement needs inspection
 */
function hasPendingCustomerReplacement(string $baseDir): bool
{
    if (!is_dir($baseDir)) {
        return false;
    }
    $entries = scandir($baseDir);
    if ($entries === false) {
        outputError('Cannot inspect customer backup replacements: ' . $baseDir);
        return true;
    }
    foreach ($entries as $entry) {
        if (preg_match('/^\..+\.(?:new|previous)-[a-f0-9]{16}$/D', $entry) === 1) {
            outputError('Unpublished customer backup replacement needs inspection: ' . $baseDir . '/' . $entry);
            return true;
        }
    }

    return false;
}

/**
 * Back up all active Froxlor customers: vhosts, databases, mailboxes, logs
 *
 * @param array     $config  Merged config array
 * @param \PDO      $db      Froxlor database connection
 * @param \PDO|null $dbRoot  Root MySQL connection when database dumps are enabled
 * @param array     $sql     Froxlor $sql credentials array
 * @param array     $sqlRoot Froxlor $sql_root credentials array
 * @param string    $today   Date string YYYY-MM-DD
 * @param boolean   $verbose Show per-file detail
 * @param boolean   $dryRun  Simulate without making changes
 *
 * @return array Completion flag and total backup size in bytes
 */
function backupCustomers(
    array $config,
    \PDO $db,
    ?\PDO $dbRoot,
    array $sql,
    array $sqlRoot,
    string $today,
    bool $verbose,
    bool $dryRun
): array {
    outputSection('Customers Backup');

    $method          = $config['archive_method'];
    $backupBaseDir   = $config['customers']['dir'];
    $keepLocalDays   = (int) $config['keep_local_days'];
    $cleanBefore     = (bool) $config['clean_before_backup'] && $keepLocalDays === 0;
    $vhostsCfg       = $config['customers']['vhosts'];
    $excludeFiles    = $vhostsCfg['exclude_files'];
    $logfilesDir     = $config['customers']['logs'] ? getSetting($db, 'logfiles_directory') : null;
    $useGoaccess     = $vhostsCfg['enabled'] && $vhostsCfg['goaccess']
        && getSetting($db, 'traffictool') === 'goaccess';
    $allOk = true;
    if ($config['customers']['logs'] && (!$logfilesDir || !is_dir($logfilesDir))) {
        outputError('Customer log directory is unavailable');
        $allOk = false;
    }

    $customers = dbQuery($db, 'SELECT customerid, loginname, documentroot, firstname, name'
        . ' FROM ' . TABLE_PANEL_CUSTOMERS
        . ' WHERE deactivated = 0'
        . ' ORDER BY loginname');

    if (empty($customers)) {
        outputError('No active customers found; customer backup is incomplete');

        return [false, 0];
    }

    $totalSize = 0;

    foreach ($customers as $customer) {
        $start      = microtime(true);
        $loginname  = $customer['loginname'];
        $fullname   = trim($customer['firstname'] . ' ' . $customer['name']);
        $customerBaseDir = $backupBaseDir . '/' . $loginname;
        $backupDir       = $keepLocalDays > 0 ? $customerBaseDir . '/' . $today : $customerBaseDir;

        output('Backing up [' . $loginname . '] (' . $fullname . ') ...');

        if (
            !isSafePathComponent($loginname)
            || ($vhostsCfg['enabled'] && is_dir($customer['documentroot'])
                && !isDestinationOutsideSource($backupBaseDir, $customer['documentroot']))
        ) {
            outputError('Unsafe customer backup path: ' . $loginname);
            $allOk = false;
            continue;
        }

        if ($dryRun) {
            $action = $cleanBefore ? 'would recreate' : 'would create';
            output('  [dry-run] ' . $action . ': ' . $backupDir);
            outputDone($start);
            continue;
        }

        $finalDir = $backupDir;
        if ($cleanBefore) {
            $backupDir = prepareBackupReplacement($finalDir);
            if ($backupDir === '') {
                $allOk = false;
                continue;
            }
        }

        ensureDir($backupDir);
        $customerOk = true;

        // Vhosts
        if ($vhostsCfg['enabled']) {
            $backupDirVhosts = $vhostsCfg['separate_archives'] ? $backupDir . '/vhosts' : $backupDir;
            ensureDir($backupDirVhosts);

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

            if ($vhostsCfg['separate_archives']) {
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
                    if (
                        !isSafePathComponent($domain['domain'])
                        || ($domain['parent_domain'] !== null
                            && !isSafePathComponent($domain['parent_domain']))
                        || !isDestinationOutsideSource($backupBaseDir, $domain['documentroot'])
                    ) {
                        outputError('Unsafe domain backup path: ' . $domain['domain']);
                        $customerOk = false;
                        continue;
                    }
                    ensureDir($domainDir);

                    if ($verbose) {
                        output('  VHost: ' . $domain['domain']);
                    }

                    $archived = archiveDirectory(
                        $domain['documentroot'],
                        $domainDir . '/' . $domain['domain'],
                        $method,
                        $excludeFiles
                    );
                    if ($archived) {
                        $vhostCount++;
                    } else {
                        $customerOk = false;
                    }
                }

                summaryAdd('vhosts', $vhostCount);
            } else {
                // Single archive for all vhosts
                $combinedExclusions = combinedVhostExclusions(
                    $customer['documentroot'],
                    $domains,
                    $excludeFiles,
                    $vhostsCfg['exclude_domains']
                );
                $archived = $combinedExclusions !== null && archiveDirectory(
                    $customer['documentroot'],
                    $backupDirVhosts . '/vhosts',
                    $method,
                    $combinedExclusions
                );
                if ($archived) {
                    summaryAdd('vhosts');
                } else {
                    $customerOk = false;
                }
            }

            // GoAccess database
            if ($useGoaccess) {
                $goaccessDir = $customer['documentroot'] . '/goaccess';
                if (is_dir($goaccessDir)) {
                    if ($verbose) {
                        output('  GoAccess: archiving');
                    }
                    if (!archiveDirectory($goaccessDir, $backupDir . '/vhosts-goaccess', $method)) {
                        $customerOk = false;
                    }
                }
            }
        }

        // Logs
        if ($config['customers']['logs'] && $logfilesDir) {
            if ($verbose) {
                output('  Logs: archiving');
            }
            $logMatches = glob($logfilesDir . '/' . $loginname . '*.log');
            if ($logMatches === false) {
                outputError('Cannot list customer log files: ' . $loginname);
                $customerOk = false;
            } elseif ($logMatches !== [] && !archiveGlob($loginname . '*.log', $logfilesDir, $backupDir . '/vhosts-log', $method)) {
                $customerOk = false;
            }
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
                    if (
                        !isSafePathComponent($mailbox['domain']) || !isSafePathComponent($mailbox['username'])
                        || !isDestinationOutsideSource($backupBaseDir, $mailbox['homedir'] . $mailbox['maildir'])
                    ) {
                        outputError('Unsafe mailbox backup path: ' . $mailbox['username']);
                        $customerOk = false;
                        continue;
                    }
                    $mailDir = $backupDir . '/mail/' . $mailbox['domain'];
                    ensureDir($mailDir);

                    if ($verbose) {
                        output('  Mail: ' . $mailbox['username'] . '@' . $mailbox['domain']);
                    }

                    $archived = archiveDirectory(
                        $mailbox['homedir'] . $mailbox['maildir'],
                        $mailDir . '/' . $mailbox['username'],
                        $method
                    );
                    if ($archived) {
                        $mailCount++;
                    } else {
                        $customerOk = false;
                    }
                }
                summaryAdd('mailboxes', $mailCount);
            }
        }

        // Databases
        if ($config['customers']['databases']) {
            if ($dbRoot === null) {
                throw new \RuntimeException('Database backup connection is unavailable');
            }
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

                    $dumped = dumpDatabase(
                        $database['databasename'],
                        $dbDir,
                        $dbRoot,
                        $database['databasename'],
                        $sql['host'],
                        $sqlRoot[0]['host'],
                        $method
                    );
                    if ($dumped) {
                        $dbCount++;
                    } else {
                        $customerOk = false;
                    }
                }
                summaryAdd('databases', $dbCount);
            }
        }

        // Local retention cleanup for this customer
        if ($cleanBefore) {
            if ($customerOk) {
                $customerOk = publishBackupReplacement($backupDir, $finalDir);
            }
            if (!$customerOk) {
                outputError('Customer replacement not published; inspect ' . $backupDir);
            }
        }

        $customerSize = $customerOk ? dirSize($finalDir) : 0;
        if ($customerSize === null) {
            $customerOk = false;
            $customerSize = 0;
        }
        if ($customerOk) {
            $totalSize += $customerSize;
            summaryAdd('customers');
        } else {
            $allOk = false;
        }
        outputDone($start, $customerSize);
    }

    if ($totalSize > 0) {
        output('Customers total: ' . formatSize($totalSize));
    }

    if (hasPendingCustomerReplacement($backupBaseDir)) {
        $allOk = false;
    }

    return [$allOk, $totalSize];
}

/**
 * Back up system config files from the file list
 *
 * @param array   $config  Merged config array
 * @param string  $destDir Shared system destination
 * @param boolean $dryRun  Simulate without making changes
 *
 * @return boolean True when the system archive is complete
 */
function backupSystem(array $config, string $destDir, bool $dryRun): bool
{
    outputSection('System Backup');

    $start    = microtime(true);
    $fileList = $config['system']['file_list'] ?? '';

    if (empty($fileList) || !file_exists($fileList)) {
        outputError('System file list not available.');

        return false;
    }

    $fileLists = [$fileList];
    $localFileList = $fileList . '.local';

    if (file_exists($localFileList)) {
        $fileLists[] = $localFileList;
        output('Including local file list: ' . basename($localFileList));
    }

    output('Archiving system config files ...');

    if ($dryRun) {
        output('[dry-run] would archive: ' . $destDir . '/system-config');
        outputDone($start);

        return true;
    }

    ensureDir($destDir);
    $ok = archiveFileList($fileLists, $destDir . '/system-config', $config['archive_method']);

    if (!$ok) {
        outputError('System backup archive was not created.');
    }

    outputDone($start);

    return $ok;
}

/**
 * Back up the Froxlor control panel files and database
 *
 * @param array     $config  Merged config array
 * @param string    $destDir Shared system destination
 * @param \PDO|null $dbRoot  Root MySQL connection outside dry-run
 * @param array     $sql     Froxlor $sql credentials array
 * @param array     $sqlRoot Froxlor $sql_root credentials array
 * @param boolean   $dryRun  Simulate without making changes
 *
 * @return boolean True when files and database complete
 */
function backupControlPanel(
    array $config,
    string $destDir,
    ?\PDO $dbRoot,
    array $sql,
    array $sqlRoot,
    bool $dryRun
): bool {
    outputSection('Control Panel Backup');

    $start         = microtime(true);
    $method        = $config['archive_method'];
    $panelDir      = $config['control_panel']['path'];

    if ($dryRun) {
        output('[dry-run] would archive: ' . $panelDir . ' -> ' . $destDir . '/control-panel-files');
        output('[dry-run] would dump DB: ' . $sql['db'] . ' -> ' . $destDir . '/control-panel-database');
        outputDone($start);

        return true;
    }

    if ($dbRoot === null) {
        throw new \RuntimeException('Control panel database connection is unavailable');
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
    $dumped = dumpDatabase(
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

    return $ok && $dumped;
}

/**
 * Select local backup groups that have an enabled producer
 *
 * @param array $config Merged config array
 *
 * @return array<string, string> Group names and local directories
 */
function selectedBackupGroups(array $config): array
{
    $groups = [];
    if ($config['customers']['enabled']) {
        $groups['customers'] = $config['customers']['dir'];
    }
    if ($config['system']['enabled'] || $config['control_panel']['enabled']) {
        $groups['system'] = $config['system']['dir'];
    }

    return $groups;
}

/**
 * Sync backups to a remote host via rsync, with optional old-backup cleanup
 *
 * @param array   $config   Merged config array
 * @param string  $today    Date string YYYY-MM-DD
 * @param boolean $dryRun   Simulate without making changes
 * @param array   $complete Completed local groups
 *
 * @return boolean
 */
function stepSyncRsync(array $config, string $today, bool $dryRun, array $complete): bool
{
    outputSection('Sync: Rsync');

    $start    = microtime(true);
    $cfg      = $config['rsync'];
    $host     = $cfg['ssh_host'];
    $hostname = trim(gethostname()) ?: 'localhost';

    $pathCustomers = $cfg['path_customers'] !== '' ? $cfg['path_customers'] : 'backups/' . $hostname . '/clients';
    $pathSystem = $cfg['path_system'] !== '' ? $cfg['path_system'] : 'backups/' . $hostname . '/system';

    $groups = selectedBackupGroups($config);
    $remotePaths = ['customers' => $pathCustomers, 'system' => $pathSystem];

    if ($dryRun) {
        foreach ($groups as $name => $localDir) {
            output('[dry-run] would rsync to ' . $host . ':' . $remotePaths[$name] . '/' . $today);
        }
        outputDone($start);

        return true;
    }

    $deleted = 0;
    $allOk = true;
    foreach ($groups as $name => $localDir) {
        if (!($complete[$name] ?? false)) {
            outputError('Skipping rsync of incomplete ' . $name . ' backup');
            $allOk = false;
            continue;
        }
        $remoteDir = $remotePaths[$name];
        if ($cfg['delete_strategy'] === 'before') {
            $removed = rsyncDeleteExpired($host, $remoteDir, $today, $cfg['keep_days']);
            if ($removed === null) {
                $allOk = false;
                continue;
            }
            $deleted += $removed;
        }

        output('Syncing ' . $name . ' ...');
        if (!doRsync($host, $localDir, $remoteDir . '/' . $today)) {
            $allOk = false;
            continue;
        }
        if ($cfg['delete_strategy'] === 'after') {
            $removed = rsyncDeleteExpired($host, $remoteDir, $today, $cfg['keep_days']);
            if ($removed === null) {
                $allOk = false;
                continue;
            }
            $deleted += $removed;
        }
        summaryAdd('syncs');
    }

    if ($deleted > 0) {
        output('Cleanup: removed ' . $deleted . ' old backup(s) (> ' . $cfg['keep_days'] . ' days)');
    }

    outputDone($start);

    return $allOk;
}

/**
 * Sync backups to S3 via s3cmd, with optional old-backup cleanup
 *
 * @param array   $config   Merged config array
 * @param string  $today    Date string YYYY-MM-DD
 * @param boolean $dryRun   Simulate without making changes
 * @param array   $complete Completed local groups
 *
 * @return boolean
 */
function stepSyncS3(array $config, string $today, bool $dryRun, array $complete): bool
{
    outputSection('Sync: S3');

    $start    = microtime(true);
    $cfg      = $config['s3'];
    $hostname = trim(gethostname()) ?: 'localhost';

    $pathCustomers = $cfg['path_customers'] !== '' ? $cfg['path_customers'] : $cfg['bucket'] . '/' . $hostname . '/clients';
    $pathSystem = $cfg['path_system'] !== '' ? $cfg['path_system'] : $cfg['bucket'] . '/' . $hostname . '/system';

    $groups = selectedBackupGroups($config);
    $remotePaths = ['customers' => $pathCustomers, 'system' => $pathSystem];

    if ($dryRun) {
        foreach ($groups as $name => $localDir) {
            output('[dry-run] would s3 sync to ' . $remotePaths[$name] . '/' . $today);
        }
        outputDone($start);

        return true;
    }

    $deleted = 0;
    $allOk = true;
    foreach ($groups as $name => $localDir) {
        if (!($complete[$name] ?? false)) {
            outputError('Skipping S3 sync of incomplete ' . $name . ' backup');
            $allOk = false;
            continue;
        }
        $remoteDir = $remotePaths[$name];
        if ($cfg['delete_strategy'] === 'before') {
            $removed = s3DeleteExpired($remoteDir, $today, $cfg['keep_days']);
            if ($removed === null) {
                $allOk = false;
                continue;
            }
            $deleted += $removed;
        }

        output('Syncing ' . $name . ' ...');
        if (!doS3Sync($localDir, $remoteDir . '/' . $today)) {
            $allOk = false;
            continue;
        }
        if ($cfg['delete_strategy'] === 'after') {
            $removed = s3DeleteExpired($remoteDir, $today, $cfg['keep_days']);
            if ($removed === null) {
                $allOk = false;
                continue;
            }
            $deleted += $removed;
        }
        summaryAdd('syncs');
    }

    if ($deleted > 0) {
        output('Cleanup: removed ' . $deleted . ' old backup(s) (> ' . $cfg['keep_days'] . ' days)');
    }

    outputDone($start);

    return $allOk;
}
