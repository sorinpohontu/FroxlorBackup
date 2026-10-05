<?php

/**
 * @package     Froxlor Backup
 *
 * @subpackage  Review Tests
 *
 * @author      Sorin Pohontu <sorin@frontline.ro>
 * @copyright   2025-2026 Frontline softworks <https://www.frontline.ro>
 * @license     https://opensource.org/licenses/BSD-3-Clause
 *
 * @since       2026.10.05
 */

/**
 * Assert a review fixture condition
 *
 * @param boolean $condition Condition result
 * @param string  $message   Failure description
 *
 * @return void
 */
function reviewCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Remove only the fixture directory created by this script
 *
 * @param string $path Fixture path
 *
 * @return void
 */
function reviewRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $item) {
            if (!$item->isDot()) {
                reviewRemove($item->getPathname());
            }
        }
        rmdir($path);
        return;
    }
    unlink($path);
}

/**
 * Run a disposable backup installation with fixture configuration
 *
 * @param string   $installation Fixture installation directory
 * @param array    $config       Local overrides
 * @param string[] $arguments    CLI flags
 * @param string   $helpers      Fixture helper source
 *
 * @return array{0: int, 1: string} Exit code and captured output
 */
function reviewRunStampFixture(string $installation, array $config, array $arguments, string $helpers): array
{
    file_put_contents($installation . '/config.local.php', '<?php return ' . var_export($config, true) . ';');
    chmod($installation . '/config.local.php', 0600);
    file_put_contents($installation . '/lib/BackupHelpers.php', $helpers);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($installation . '/backup.php');
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);

    return [$status, implode(PHP_EOL, $lines)];
}

/**
 * Inject one failure into a disposable copy of helper source
 *
 * @param string $source      Original fixture source
 * @param string $operation   Unique operation to replace
 * @param string $replacement Fixture operation
 *
 * @return string Modified fixture source
 */
function reviewReplaceFixtureCode(string $source, string $operation, string $replacement): string
{
    reviewCheck(substr_count($source, $operation) === 1, 'Fixture operation is not unique: ' . $operation);

    return str_replace($operation, $replacement, $source);
}

/**
 * Assert preservation of a previous success stamp
 *
 * @param string  $path Stamp path
 * @param string  $text Previous contents
 * @param integer $time Previous modification time
 *
 * @return void
 */
function reviewStampUnchanged(string $path, string $text, int $time): void
{
    clearstatcache(true, $path);
    reviewCheck(file_get_contents($path) === $text && filemtime($path) === $time, 'Previous success stamp changed');
}

require_once dirname(__DIR__) . '/lib/BackupHelpers.php';
require_once dirname(__DIR__) . '/lib/BackupSteps.php';

reviewCheck(resolveTimezone('Europe/Bucharest') === 'Europe/Bucharest', 'Explicit timezone was not preserved');
reviewCheck(resolveTimezone('Invalid/Timezone') === null, 'Invalid timezone was accepted');
$timezoneSource = null;
reviewCheck(
    resolveTimezone('', $timezoneSource) !== null && $timezoneSource !== null,
    'System timezone could not be resolved'
);

umask(0077);
putenv('LC_ALL=C');
$root = realpath(sys_get_temp_dir()) . '/froxlor-review-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
register_shutdown_function(function () use ($root): void {
    if (is_dir($root)) {
        reviewRemove($root);
    }
});

$froxlor = $root . '/froxlor';
ensureDir($froxlor . '/lib');
$userdata = $froxlor . '/lib/userdata.inc.php';
$tables = $froxlor . '/lib/tables.inc.php';
file_put_contents($userdata, '<?php $sql = [\'host\' => \'localhost\', \'db\' => \'froxlor\', \'user\' => \'panel\', \'password\' => \'secret\'];'
    . ' $sql_root = [[\'host\' => \'localhost\', \'user\' => \'root\', \'password\' => \'root-secret\']];');
chmod($userdata, 0660);
file_put_contents($tables, '<?php '
    . 'define(\'TABLE_PANEL_CUSTOMERS\', \'panel_customers\');'
    . 'define(\'TABLE_PANEL_DOMAINS\', \'panel_domains\');'
    . 'define(\'TABLE_PANEL_DATABASES\', \'panel_databases\');'
    . 'define(\'TABLE_PANEL_SETTINGS\', \'panel_settings\');'
    . 'define(\'TABLE_MAIL_USERS\', \'mail_users\');');
chmod($tables, 0644);
$validTables = file_get_contents($tables);
file_put_contents($tables, str_replace('panel_customers', 'panel_customers;DROP', $validTables));
reviewCheck(loadFroxlorSettings($froxlor) === null, 'Unsafe Froxlor table name was accepted');
file_put_contents($tables, $validTables);
$froxlorSettings = loadFroxlorSettings($froxlor);
reviewCheck(
    $froxlorSettings !== null && $froxlorSettings['sql']['password'] === 'secret'
    && TABLE_PANEL_CUSTOMERS === 'panel_customers',
    'Isolated Froxlor settings reader failed'
);

$base = $root . '/backups/customers';
ensureDir($base);
reviewCheck(
    !isSafeBackupRoot('/') && !isSafeBackupRoot('/var/backups/../etc')
    && !isSafeRemotePath('../backups') && !isSafeS3Path('s3:///backups'),
    'Unsafe path passed validation'
);
$final = $base . '/web1';
ensureDir($final);
file_put_contents($final . '/old', 'old');
$stage = prepareBackupReplacement($final);
reviewCheck($stage !== '' && is_file($final . '/old'), 'Replacement preparation removed previous backup');
file_put_contents($stage . '/new', 'new');
reviewCheck(publishBackupReplacement($stage, $final), 'Replacement publication failed');
reviewCheck(is_file($final . '/new') && !file_exists($final . '/old'), 'Replacement contents are wrong');
$pending = $base . '/.web1.new-0123456789abcdef';
ensureDir($pending);
reviewCheck(hasPendingCustomerReplacement($base), 'Unpublished customer replacement was not detected');
reviewRemove($pending);
reviewCheck(!hasPendingCustomerReplacement($base), 'Published customer root appears incomplete');

$dated = $base . '/web2';
ensureDir($dated . '/2000-01-01');
ensureDir($dated . '/2026-09-24');
ensureDir($dated . '/2026-99-99');
reviewCheck(cleanLocalBackups($dated, '2026-09-24', 7) === 1, 'Local retention count is wrong');
reviewCheck(!is_dir($dated . '/2000-01-01') && is_dir($dated . '/2026-09-24')
    && is_dir($dated . '/2026-99-99'), 'Local retention crossed date boundary');

$keys = [
    's3://bucket/2000-01-01/clients/2026-09-24/a.7z',
    's3://bucket/2000-01-01/clients/2000-01-01/a.7z',
    's3://bucket/2000-01-01/clients/2026-09-24/2000-01-01',
    's3://bucket/2000-01-01/clientship/2000-01-01/a.7z',
    's3://bucket/2000-01-01/clients/2000-01-01/mail/example.com/contact@example.com.7z',
];
reviewCheck(
    s3ExpiredObjects('s3://bucket/2000-01-01/clients', '2026-09-24', 7, $keys) === [$keys[1], $keys[4]],
    'S3 retention selected a current or sibling object'
);
$s3Base = 's3://bucket/2000-01-01/clients';
reviewCheck(
    isSafeS3ExpiredObject($s3Base, '2026-09-24', 7, $keys[4])
    && !isSafeS3ExpiredObject($s3Base, '2026-09-24', 7, $keys[0])
    && !isSafeS3ExpiredObject($s3Base, '2026-09-24', 7, $keys[3])
    && !isSafeS3ExpiredObject($s3Base, '2026-09-24', 7, $s3Base . '/2000-01-01/../other.7z')
    && !isSafeS3ExpiredObject($s3Base, '2026-09-24', 7, $s3Base . '/2000-01-01/*.7z'),
    'S3 deletion validation crossed an expired snapshot boundary'
);
$s3Fixture = $root . '/s3-fixture';
ensureDir($s3Fixture);
$s3Path = $s3Fixture . '/s3cmd';
$s3DeleteLog = $s3Fixture . '/deleted';
file_put_contents($s3Path, <<<'SH'
#!/bin/sh
case "$1" in
    ls)
        printf '2026-09-17 23:40 1 %s\n' 's3://bucket/2000-01-01/clients/2000-01-01/a.7z'
        printf '2026-09-17 23:40 1 %s\n' 's3://bucket/2000-01-01/clients/2000-01-01/mail/example.com/contact@example.com.7z'
        printf '2026-09-24 23:40 1 %s\n' 's3://bucket/2000-01-01/clients/2026-09-24/a.7z'
        ;;
    del)
        printf '%s\n' "$2" >> "$S3_DELETE_LOG"
        ;;
    *) exit 1 ;;
esac
SH
);
chmod($s3Path, 0700);
$originalPath = getenv('PATH');
putenv('PATH=' . $s3Fixture . PATH_SEPARATOR . $originalPath);
putenv('S3_DELETE_LOG=' . $s3DeleteLog);
reviewCheck(
    !s3DeleteFiles($s3Base, '2026-09-24', 7, [$keys[1], $keys[0]]) && !file_exists($s3DeleteLog),
    'S3 deletion began before every key was validated'
);
reviewCheck(
    s3DeleteExpired($s3Base, '2026-09-24', 7) === 2
    && file_get_contents($s3DeleteLog) === $keys[1] . "\n" . $keys[4] . "\n",
    'S3 deletion rejected a valid mailbox archive'
);
putenv('PATH=' . $originalPath);
putenv('S3_DELETE_LOG');

$source = $root . '/vhosts';
ensureDir($source . '/a.example/cache');
ensureDir($source . '/b.example/cache');
file_put_contents($source . '/a.example/app.php', 'app');
file_put_contents($source . '/a.example/cache/skip', 'cache');
file_put_contents($source . '/b.example/cache/skip', 'cache');
$exclusions = combinedVhostExclusions($source, [
    ['domain' => 'a.example', 'documentroot' => $source . '/a.example'],
    ['domain' => 'b.example', 'documentroot' => $source . '/b.example'],
], ['cache'], ['b.example']);
reviewCheck(
    in_array('a.example/cache', $exclusions, true) && in_array('b.example', $exclusions, true),
    'Combined vhost exclusions missed a document root'
);

$archiveDir = $root . '/archives';
ensureDir($archiveDir);
reviewCheck(archiveDirectory($source, $archiveDir . '/vhosts', 'tar', $exclusions), 'Tar fixture failed');
$listing = [];
$status = 0;
exec('tar -tzf ' . escapeshellarg($archiveDir . '/vhosts.tar.gz'), $listing, $status);
reviewCheck($status === 0 && in_array('./a.example/app.php', $listing, true), 'Tar fixture lost included content');
reviewCheck((fileperms($archiveDir . '/vhosts.tar.gz') & 0077) === 0, 'Archive permissions are too broad');
foreach ($listing as $member) {
    reviewCheck(
        strpos($member, 'cache') === false && strpos($member, 'b.example') === false,
        'Tar fixture included excluded content'
    );
}

$previousArchive = file_get_contents($archiveDir . '/vhosts.tar.gz');
$failedTemporary = $archiveDir . '/failed-temporary.tar.gz';
reviewCheck(
    !runArchiveCommand('false', $failedTemporary, $archiveDir . '/vhosts.tar.gz'),
    'Failed archive command was reported successful'
);
reviewCheck(
    file_get_contents($archiveDir . '/vhosts.tar.gz') === $previousArchive,
    'Failed archive command replaced a previous archive'
);

$baseList = $root . '/system-list';
file_put_contents($baseList, $source . '/a.example/app.php' . "\n");
file_put_contents($baseList . '.local', $source . '/a.example/app.php' . "\n");
reviewCheck(
    count(archiveFileListEntries([$baseList, $baseList . '.local'])) === 1,
    'System file lists were not combined in memory'
);
$cfg = require dirname(__DIR__) . '/config.php';
$cfg['system']['file_list'] = $baseList;
$before = scandir($root);
reviewCheck(backupSystem($cfg, $archiveDir, true), 'System dry-run failed');
reviewCheck(scandir($root) === $before, 'System dry-run created a temporary list');

$cfg['control_panel']['enabled'] = true;
$cfg['control_panel']['path'] = $source;
reviewCheck(
    backupControlPanel($cfg, $archiveDir, null, ['db' => 'froxlor'], [], true),
    'Control-panel dry-run required a database connection'
);
$cfg['rsync']['ssh_host'] = 'backup-server';
$cfg['s3']['bucket'] = 's3://bucket';
outputInit();
reviewCheck(stepSyncRsync($cfg, '2026-09-24', true, ['system' => true]), 'Rsync dry-run failed');
reviewCheck(strpos(outputGet(), '/system/2026-09-24') !== false
    && strpos(outputGet(), '/clients/2026-09-24') === false, 'Panel-only rsync target is wrong');
outputInit();
reviewCheck(stepSyncS3($cfg, '2026-09-24', true, ['system' => true]), 'S3 dry-run failed');
reviewCheck(strpos(outputGet(), '/system/2026-09-24') !== false
    && strpos(outputGet(), '/clients/2026-09-24') === false, 'Panel-only S3 target is wrong');
outputInit();
reviewCheck(
    !stepSyncRsync($cfg, '2026-09-24', false, ['system' => false]),
    'Incomplete rsync group was published'
);
reviewCheck(
    strpos(outputGet(), 'Skipping rsync of incomplete system backup') !== false,
    'Incomplete rsync group was not reported'
);
outputInit();
reviewCheck(
    !stepSyncS3($cfg, '2026-09-24', false, ['system' => false]),
    'Incomplete S3 group was published'
);

$originalPath = getenv('PATH');
$sshFixture = $root . '/ssh-fixture';
ensureDir($sshFixture);
$sshPath = $sshFixture . '/ssh';
$rsyncPath = $sshFixture . '/rsync';
$remoteCommandLog = $sshFixture . '/commands';
file_put_contents($sshPath, <<<'SH'
#!/bin/sh
case "$2" in
    'mkdir -p '*) exit 0 ;;
    'rm -rf '*) printf '%s\n' "$2" >> "$REMOTE_COMMAND_LOG"; exit 0 ;;
esac
printf 'Command not found\n' >&2
exit 8
SH
);
file_put_contents($rsyncPath, <<<'SH'
#!/bin/sh
printf 'drwxr-xr-x 0 2026/09/24 00:00:00 .\n'
printf 'drwxr-xr-x 0 2026/09/24 00:00:00 2026-01-01\n'
printf '%srw-r--r-- 0 2026/09/24 00:00:00 2026-01-02\n' '-'
printf 'lrwxr-xr-x 0 2026/09/24 00:00:00 2026-01-03\n'
printf 'drwxr-xr-x 0 2026/09/24 00:00:00 2026-09-24\n'
printf 'drwxr-xr-x 0 2026/09/24 00:00:00 nested/2000-01-01\n'
printf '%srwxr-xr-x 0 2026/09/24 00:00:00 other name\n' 'd'
if [ "$LISTING_MODE" = malformed ]; then
    printf '?rwxr-xr-x 0 2026/09/24 00:00:00 2026-01-04\n'
fi
SH
);
chmod($sshPath, 0700);
chmod($rsyncPath, 0700);
putenv('PATH=' . $sshFixture . PATH_SEPARATOR . $originalPath);
putenv('REMOTE_COMMAND_LOG=' . $remoteCommandLog);
$remoteBase = 'backups/test-host/clients';
$listed = rsyncList('backup-server', $remoteBase);
reviewCheck($listed === ['2026-01-01', '2026-09-24'], 'Remote listing accepted a file or symlink');
$removed = rsyncDeleteExpired('backup-server', $remoteBase, '2026-09-24', 7);
reviewCheck(
    $removed === 1
    && file_get_contents($remoteCommandLog) === "rm -rf -- 'backups/test-host/clients/2026-01-01'\n",
    'Remote retention selected the wrong entry'
);
putenv('LISTING_MODE=malformed');
reviewCheck(rsyncList('backup-server', $remoteBase) === null, 'Unverifiable remote listing was accepted');
putenv('PATH=' . $originalPath);
putenv('REMOTE_COMMAND_LOG');
putenv('LISTING_MODE');

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
reviewCheck($pair !== false, 'Cannot create SMTP protocol fixture');
fwrite($pair[0], "250 OK\r\n250-first\r\n250 second\r\n");
reviewCheck(
    smtpReadReply($pair[1]) === 250 && smtpReadReply($pair[1]) === 250,
    'SMTP reader mishandled single or multi-line response'
);
fwrite($pair[0], "250-first\r\n550 second\r\n");
reviewCheck(smtpReadReply($pair[1]) === null, 'SMTP reader accepted inconsistent response codes');
fclose($pair[0]);
fclose($pair[1]);

$stampDir = $root . '/status';
ensureDir($stampDir);
$stampPath = $stampDir . '/last-success';
$stampRoots = [$root . '/stamp-clients', $root . '/stamp-system'];
$installation = $root . '/stamp-app';
ensureDir($installation . '/lib');
foreach (['backup.php', 'config.php', 'lib/BackupHelpers.php', 'lib/BackupSteps.php'] as $file) {
    copy(dirname(__DIR__) . '/' . $file, $installation . '/' . $file);
    chmod($installation . '/' . $file, 0600);
}
$helperSource = file_get_contents($installation . '/lib/BackupHelpers.php');
if (PHP_OS === 'Darwin') {
    $helperSource = reviewReplaceFixtureCode(
        $helperSource,
        'function dirSize(string $path): ?int' . "\n{\n",
        'function dirSize(string $path): ?int' . "\n{\n    return 0;\n"
    );
}
$stampSource = $root . '/stamp-content';
file_put_contents($stampSource, 'Fixture backup content');
$stampList = $root . '/stamp-list';
file_put_contents($stampList, $stampSource . PHP_EOL);
$stampConfig = [
    'timezone'       => 'UTC',
    'archive_method' => 'tar',
    'status_file'    => $stampPath,
    'customers'      => ['dir' => $stampRoots[0]],
    'system'         => [
        'enabled'   => true,
        'dir'       => $stampRoots[1],
        'file_list' => $stampList,
    ],
];
$unrelatedTemporary = $stampPath . '.tmp';
file_put_contents($unrelatedTemporary, 'Unrelated temporary file');
$before = scandir($stampDir);
list($status, $log) = reviewRunStampFixture($installation, $stampConfig, ['--check-install'], $helperSource);
reviewCheck($status === 0 && scandir($stampDir) === $before, 'Installation check created the initial stamp');
reviewCheck(strpos($log, '[OK] Backup success status: ' . $stampPath . ' (directory writable)') !== false, 'Installation check omitted status_file');
list($status, $log) = reviewRunStampFixture($installation, $stampConfig, [], $helperSource);
reviewCheck($status === 0, 'Success-stamp fixture failed: ' . $log);
reviewCheck(strpos($log, 'Backup success status updated: ' . $stampPath) !== false, 'Successful publication was not reported');
$stampText = file_get_contents($stampPath);
reviewCheck(
    date_create(trim($stampText)) !== false && preg_match('/^\d{4}-\d{2}-\d{2}T.*\n$/D', $stampText) === 1,
    'Success stamp has an invalid timestamp'
);
clearstatcache(true, $stampPath);
reviewCheck((fileperms($stampPath) & 0777) === 0600, 'Success-stamp permissions are not 0600');
reviewCheck(file_get_contents($unrelatedTemporary) === 'Unrelated temporary file', 'Unrelated temporary was changed');
reviewCheck(scandir($stampDir) === ['.', '..', 'last-success', 'last-success.tmp'], 'Success left a temporary stamp');

$previousStamp = '2000-01-01T00:00:00+00:00' . PHP_EOL;
$previousTime = 946684800;
file_put_contents($stampPath, $previousStamp);
touch($stampPath, $previousTime);
list($status, $log) = reviewRunStampFixture($installation, $stampConfig, [], $helperSource);
clearstatcache(true, $stampPath);
reviewCheck($status === 0 && filemtime($stampPath) > $previousTime, 'Successful run did not replace the old stamp');

file_put_contents($stampPath, $previousStamp);
touch($stampPath, $previousTime);
foreach ([['--dry-run'], ['--check-install']] as $arguments) {
    $before = scandir($stampDir);
    list($status, $log) = reviewRunStampFixture($installation, $stampConfig, $arguments, $helperSource);
    reviewCheck($status === 0, 'Read-only stamp fixture failed: ' . $log);
    if ($arguments === ['--dry-run']) {
        reviewCheck(
            strpos($log, '[dry-run] would update backup success status after a successful backup: ' . $stampPath) !== false,
            'Dry run omitted status_file'
        );
    }
    reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
    reviewCheck(scandir($stampDir) === $before, 'Read-only command created a stamp probe');
}
file_put_contents($stampList, $root . '/missing-stamp-source' . PHP_EOL);
list($status, $log) = reviewRunStampFixture($installation, $stampConfig, [], $helperSource);
reviewCheck($status === 1 && strpos($log, 'Completed with errors.') !== false, 'Step failure did not fail the run');
reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
file_put_contents($stampList, $stampSource . PHP_EOL);

$disabledConfig = $stampConfig;
$disabledConfig['status_file'] = '';
$before = scandir($stampDir);
list($status, $log) = reviewRunStampFixture($installation, $disabledConfig, [], $helperSource);
reviewCheck($status === 0 && scandir($stampDir) === $before, 'Disabled stamp wrote a file');
reviewStampUnchanged($stampPath, $previousStamp, $previousTime);

$disabledCheckLines = installationCheckLines(array_replace_recursive(require dirname(__DIR__) . '/config.php', $disabledConfig));
reviewCheck(in_array('Backup success status: disabled', $disabledCheckLines, true), 'Installation check omitted disabled status_file');
list($status, $log) = reviewRunStampFixture($installation, $disabledConfig, ['--dry-run'], $helperSource);
reviewCheck($status === 0 && strpos($log, '[dry-run] Backup success status: disabled') !== false, 'Dry run omitted disabled status_file');

$invalidPaths = [
    false,
    null,
    [],
    'relative-stamp',
    $stampDir . '/../last-success',
    $stampDir . '/./last-success',
    $stampDir . '//last-success',
    $stampDir . '/last-success/',
    $stampPath . "\0",
    $stampDir,
    $stampSource . '/missing-parent/last-success',
    $stampRoots[0],
    $stampRoots[0] . '/last-success',
    $stampRoots[1] . '/last-success',
];
foreach ($invalidPaths as $invalidPath) {
    $invalidConfig = $stampConfig;
    $invalidConfig['status_file'] = $invalidPath;
    list($status, $log) = reviewRunStampFixture($installation, $invalidConfig, ['--check-install'], $helperSource);
    reviewCheck($status === 1 && strpos($log, 'status_file') !== false, 'Invalid status_file was accepted');
    reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
}

$autoConfig = $stampConfig;
$autoDirectory = $root . '/auto-status/nested';
$autoConfig['status_file'] = $autoDirectory . '/last-success';
foreach ([['--dry-run'], ['--check-install']] as $arguments) {
    list($status, $log) = reviewRunStampFixture($installation, $autoConfig, $arguments, $helperSource);
    reviewCheck($status === 0 && !file_exists($root . '/auto-status'), 'Read-only command created stamp directories: ' . $log);
    if ($arguments === ['--check-install']) {
        reviewCheck(
            strpos($log, '[OK] Backup success status: ' . $autoConfig['status_file'] . ' (directory can be created)') !== false,
            'Installation check omitted stamp directory creatability'
        );
    } else {
        reviewCheck(
            strpos($log, '[dry-run] would create backup success status directory: ' . $autoDirectory) !== false
            && strpos($log, '[dry-run] would update backup success status after a successful backup: ' . $autoConfig['status_file']) !== false,
            'Dry run omitted stamp directory creation or publication'
        );
    }
}
file_put_contents($stampList, $root . '/missing-stamp-source' . PHP_EOL);
list($status, $log) = reviewRunStampFixture($installation, $autoConfig, [], $helperSource);
reviewCheck($status === 1 && !file_exists($root . '/auto-status'), 'Failed backup created stamp directories');
file_put_contents($stampList, $stampSource . PHP_EOL);
$mkdirHelpers = reviewReplaceFixtureCode($helperSource, '!@mkdir($directory, 0700, true)', 'true');
list($status, $log) = reviewRunStampFixture($installation, $autoConfig, [], $mkdirHelpers);
reviewCheck(
    $status === 1 && strpos($log, 'Cannot create success-stamp directory') !== false
    && !file_exists($root . '/auto-status'),
    'Stamp directory creation failure did not fail the run: ' . $log
);
list($status, $log) = reviewRunStampFixture($installation, $autoConfig, [], $helperSource);
reviewCheck($status === 0 && is_file($autoConfig['status_file']), 'Normal run did not create stamp directories: ' . $log);
foreach ([$root . '/auto-status', $autoDirectory] as $createdDirectory) {
    clearstatcache(true, $createdDirectory);
    reviewCheck(
        (fileperms($createdDirectory) & 0777) === 0700 && fileowner($createdDirectory) === posix_geteuid(),
        'Created stamp directory has unsafe permissions or ownership'
    );
}
reviewCheck((fileperms($autoConfig['status_file']) & 0777) === 0600, 'Created stamp has unsafe permissions');

symlink($stampPath, $stampDir . '/stamp-link');
symlink($stampDir . '/absent', $stampDir . '/dangling-link');
symlink($stampDir, $root . '/status-link');
foreach ([$stampDir . '/stamp-link', $stampDir . '/dangling-link', $root . '/status-link/new-stamp'] as $unsafePath) {
    outputInit();
    reviewCheck(!writeSuccessStamp($unsafePath, $stampRoots) && outputHasErrors(), 'Symlink stamp path was accepted');
    reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
}
unlink($stampDir . '/stamp-link');
unlink($stampDir . '/dangling-link');
unlink($root . '/status-link');

foreach ([[$stampPath, 0666], [$stampDir, 0777]] as $unsafePermissions) {
    chmod($unsafePermissions[0], $unsafePermissions[1]);
    list($status, $log) = reviewRunStampFixture($installation, $stampConfig, ['--check-install'], $helperSource);
    reviewCheck($status === 1 && strpos($log, 'status_file') !== false, 'Unsafe stamp permissions were accepted');
    reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
    chmod($unsafePermissions[0], $unsafePermissions[0] === $stampPath ? 0600 : 0700);
}
if (!isRootProcess()) {
    chmod($stampDir, 0500);
    $blockedConfig = $stampConfig;
    $blockedConfig['status_file'] = $stampDir . '/missing/nested/last-success';
    list($blockedStatus, $blockedLog) = reviewRunStampFixture(
        $installation,
        $blockedConfig,
        ['--check-install'],
        $helperSource
    );
    list($status, $log) = reviewRunStampFixture($installation, $stampConfig, [], $helperSource);
    chmod($stampDir, 0700);
    reviewCheck(
        $blockedStatus === 1 && strpos($blockedLog, 'status_file directory') !== false
        && !file_exists($stampDir . '/missing'),
        'Unwritable ancestor allowed missing stamp directories'
    );
    reviewCheck($status === 1 && strpos($log, 'status_file directory') !== false, 'Unwritable stamp directory was accepted');
    reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
    if (is_file('/etc/passwd') && fileowner('/etc/passwd') === 0) {
        outputInit();
        reviewCheck(
            !validateStatusFile('/etc/passwd', $stampRoots) && strpos(outputGet(), 'belong to the backup user') !== false,
            'Existing stamp owned by a different user was accepted'
        );
    }
}

$stampFaults = [
    '$fp = @fopen($temporaryFile, \'xb\');'  => '$fp = false;',
    'if (!@chmod($temporaryFile, 0600)) {'  => 'if (true) {',
    '$written = @fwrite($fp, $stamp);'      => '$written = @fwrite($fp, substr($stamp, 0, -1));',
    '$flushed = @fflush($fp);'             => '$flushed = @fflush($fp) && false;',
    '$closed = @fclose($fp);'              => '$closed = @fclose($fp) && false;',
    'if (!@rename($temporaryFile, $path)) {' => 'if (true) {',
];
foreach ($stampFaults as $operation => $replacement) {
    $faultHelpers = reviewReplaceFixtureCode($helperSource, $operation, $replacement);
    $before = scandir($stampDir);
    list($status, $log) = reviewRunStampFixture($installation, $stampConfig, [], $faultHelpers);
    reviewCheck(
        $status === 1 && preg_match('/ERROR: .*success[ -]stamp/i', $log) === 1,
        'Stamp publication failure did not fail the run: ' . $log
    );
    reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
    reviewCheck(scandir($stampDir) === $before, 'Stamp failure left temporary files');
    reviewCheck(file_get_contents($unrelatedTemporary) === 'Unrelated temporary file', 'Failure removed an unrelated file');
}

$recheckHelpers = reviewReplaceFixtureCode(
    $helperSource,
    '$closed = @fclose($fp);',
    '$closed = @fclose($fp); chmod(dirname($path), 0777);'
);
$before = scandir($stampDir);
list($status, $log) = reviewRunStampFixture($installation, $stampConfig, [], $recheckHelpers);
chmod($stampDir, 0700);
reviewCheck($status === 1 && strpos($log, 'Unsafe status_file parent') !== false, 'Publication skipped path revalidation');
reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
reviewCheck(scandir($stampDir) === $before, 'Path revalidation failure left a temporary stamp');

$smtpHelpers = reviewReplaceFixtureCode(
    $helperSource,
    'function smtpSend(array $smtpConfig, string $from, string $to, string $subject, string $body): bool' . "\n{\n",
    'function smtpSend(array $smtpConfig, string $from, string $to, string $subject, string $body): bool' . "\n{\n"
        . '    file_put_contents(__DIR__ . \'/../email-fixture\', $subject . PHP_EOL . $body);' . "\n"
        . '    outputError(\'Fixture SMTP failure\');' . "\n"
        . '    return false;' . "\n"
);
$emailConfig = $stampConfig;
$emailConfig['email'] = [
    'enabled' => true,
    'from'    => 'backup@example.com',
    'to'      => 'monitor@example.com',
    'smtp'    => [
        'host'       => 'localhost',
        'user'       => 'fixture-user',
        'password'   => 'fixture-password',
        'encryption' => '',
    ],
];
list($status, $log) = reviewRunStampFixture($installation, $emailConfig, ['--dry-run', '--test-email'], $smtpHelpers);
reviewCheck($status === 1 && strpos($log, 'Fixture SMTP failure') !== false, 'Dry-run test email was not attempted: ' . $log);
reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
$missingEmailConfig = $emailConfig;
$missingEmailConfig['status_file'] = $root . '/test-email-status/nested/last-success';
list($status, $log) = reviewRunStampFixture($installation, $missingEmailConfig, ['--dry-run', '--test-email'], $smtpHelpers);
reviewCheck(
    $status === 1 && strpos($log, 'Fixture SMTP failure') !== false && !file_exists($root . '/test-email-status'),
    'Dry-run test email created stamp directories'
);

$renameSmtpHelpers = reviewReplaceFixtureCode($smtpHelpers, 'if (!@rename($temporaryFile, $path)) {', 'if (true) {');
list($status, $log) = reviewRunStampFixture($installation, $emailConfig, [], $renameSmtpHelpers);
reviewCheck($status === 1, 'Stamp and email failure did not fail the run');
reviewStampUnchanged($stampPath, $previousStamp, $previousTime);
$emailReport = file_get_contents($installation . '/email-fixture');
reviewCheck(
    strpos($emailReport, '[Error]') !== false && strpos($emailReport, 'Cannot publish success stamp') !== false,
    'Stamp error was absent from the email subject or body'
);
list($status, $log) = reviewRunStampFixture($installation, $emailConfig, [], $smtpHelpers);
clearstatcache(true, $stampPath);
reviewCheck(
    $status === 1 && strpos($log, 'Fixture SMTP failure') !== false && filemtime($stampPath) > $previousTime,
    'SMTP failure rolled back the successful stamp'
);
reviewCheck(date_create(trim(file_get_contents($stampPath))) !== false, 'SMTP failure left an invalid stamp');

echo "Review smoke checks passed.\n";
