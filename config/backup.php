<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (php artisan swa:backup)
    |--------------------------------------------------------------------------
    |
    | No package: mysqldump for the database plus a zip of uploaded images.
    | Copy the backup folder to a second place (external drive or cloud) regularly.
    |
    */

    'mysqldump' => env('BACKUP_MYSQLDUMP', PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/mysql/bin/mysqldump.exe')
        ? 'C:/xampp/mysql/bin/mysqldump.exe'
        : 'mysqldump'),

    // An empty BACKUP_PATH falls back to the default. Must be absolute and outside public/.
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    // Backups older than this many days are deleted after a successful run.
    'keep_days' => max(1, (int) env('BACKUP_KEEP_DAYS', 14)),

];
