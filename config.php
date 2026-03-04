<?php

/**
 * @package     Froxlor Backup
 *
 * @subpackage  Config
 *
 * @author      Sorin Pohontu <sorin@frontline.ro>
 * @copyright   2026 Frontline softworks <https://www.frontline.ro>
 * @license     https://opensource.org/licenses/BSD-3-Clause
 *
 * @since       2026.03.04
 */

/*
 * Default configuration. All paths are without trailing slash (/).
 * Override values in config.local.php — only specify what you need to change.
 */

return [
    // -------------------------------------------------------------------------
    // Timezone
    // -------------------------------------------------------------------------
    'timezone' => 'Europe/Bucharest',

    // -------------------------------------------------------------------------
    // Archive method — applies to all backup sections
    // 'tar' produces .tar.gz  (always available on Linux)
    // '7z'  produces .7z      (requires: apt-get install p7zip-full)
    // -------------------------------------------------------------------------
    'archive_method'  => 'tar',

    // -------------------------------------------------------------------------
    // Local backup retention
    // 0 = flat layout — overwrite each run, one copy per customer/section
    // N = dated subdirs (YYYY-MM-DD) — keep N days, auto-delete older ones
    // -------------------------------------------------------------------------
    'keep_local_days' => 0,

    // -------------------------------------------------------------------------
    // Clean backup dir before each run (flat layout only)
    // true  = delete + recreate dir — removes stale files from deleted items
    // false = keep existing files, overwrite only what is backed up this run
    // -------------------------------------------------------------------------
    'clean_before_backup' => false,

    // -------------------------------------------------------------------------
    // Customer backups
    // -------------------------------------------------------------------------
    'customers' => [
        'enabled'  => false,
        'dir'      => '/var/backups/clients',
        'vhosts'   => [
            'enabled'            => true,
            'separate_archives'  => true,
            'goaccess'           => true,
            'exclude_domains'    => [
                // Exclude specific domains from vhost backups (e.g. large media sites)
                // 'dev.example.com',
                // 'staging.example.com',
            ],
        ],
        'databases' => true,
        'logs'      => true,
        'mails'     => true,
    ],

    // -------------------------------------------------------------------------
    // System config backup
    // -------------------------------------------------------------------------
    'system' => [
        'enabled'   => false,
        'dir'       => '/var/backups/system',
        'file_list' => __DIR__ . '/system-file-list',
    ],

    // -------------------------------------------------------------------------
    // Control panel (Froxlor) backup
    // -------------------------------------------------------------------------
    'control_panel' => [
        'enabled' => false,
        'path'    => '/var/www/html/froxlor',
    ],

    // -------------------------------------------------------------------------
    // rsync sync (requires: apt-get install rsync)
    // -------------------------------------------------------------------------
    'rsync' => [
        'enabled'         => false,
        'ssh_host'        => '',
        'bin_params'      => '-rav --delete-before',
        // Remote paths — auto-built from hostname if empty: backups/{hostname}/clients
        'path_customers'  => '',
        'path_system'     => '',
        'delete_strategy' => 'before',  // 'before' or 'after'
        'keep_days'       => 7,
    ],

    // -------------------------------------------------------------------------
    // S3 sync via s3cmd (see http://s3tools.org/download)
    // -------------------------------------------------------------------------
    's3' => [
        'enabled'         => false,
        'bin_params'      => 'sync --delete-removed --quiet --no-guess-mime-type --human-readable-sizes',
        'bucket'          => '',        // e.g. 's3://my-bucket'
        // Remote paths — auto-built from hostname if empty
        'path_customers'  => '',
        'path_system'     => '',
        'delete_strategy' => 'before',  // 'before' or 'after'
        'keep_days'       => 7,
    ],

    // -------------------------------------------------------------------------
    // Email report via SMTP (no external library required)
    // -------------------------------------------------------------------------
    'email' => [
        'enabled' => false,
        'smtp'    => [
            'host'       => '',
            'port'       => 587,
            'user'       => '',
            'password'   => '',
            'encryption' => 'tls',  // 'tls', 'ssl', or '' for none
        ],
        'from'    => '',    // e.g. 'notify@example.com'
        'to'      => '',    // e.g. 'admin@example.com'
        'subject' => 'Backup Report: {hostname} - {date}',
    ],
];
