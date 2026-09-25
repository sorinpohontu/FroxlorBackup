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
 * @since       2026.09.25
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

echo "Review smoke checks passed.\n";
