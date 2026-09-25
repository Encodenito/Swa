<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Daily backup without a package: a mysqldump of the shop database plus a zip of uploaded images.
 * The database password goes to mysqldump through the MYSQL_PWD environment variable, never on
 * the command line (visible to other processes) or in logs. `.env` is never included.
 */
class Backup extends Command
{
    protected $signature = 'swa:backup';

    protected $description = 'Back up the database and uploaded images, and delete old backups';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));
        $dir = rtrim((string) config('backup.path'), '/\\');
        File::ensureDirectoryExists($dir);

        $stamp = now()->format('Ymd-His');
        $sqlFile = "{$dir}/swa-db-{$stamp}.sql";
        $zipFile = "{$dir}/swa-images-{$stamp}.zip";

        $process = new Process([
            config('backup.mysqldump'),
            '--single-transaction',
            '--routines',
            '--default-character-set=utf8mb4',
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--result-file='.$sqlFile,
            $connection['database'],
        ], null, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], null, 600);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($sqlFile) || filesize($sqlFile) === 0) {
            File::delete($sqlFile);
            // stderr from mysqldump never contains the password (it came from the environment).
            $this->error('Database backup failed: '.trim($process->getErrorOutput() ?: 'mysqldump produced no output.'));

            return self::FAILURE;
        }

        $images = $this->zipImages($zipFile);

        $this->info('Database: '.basename($sqlFile).' ('.$this->size($sqlFile).')');
        $this->info($images === null ? 'Images: none to back up.' : "Images: {$images} file(s) in ".basename($zipFile).' ('.$this->size($zipFile).')');

        $removed = $this->prune($dir);
        $this->info("Removed {$removed} backup file(s) older than ".config('backup.keep_days').' days.');
        $this->line('Copy the backup folder to a second place: '.$dir);

        return self::SUCCESS;
    }

    private function zipImages(string $zipFile): ?int
    {
        $source = storage_path('app/public');

        if (! is_dir($source)) {
            return null;
        }

        $files = collect(File::allFiles($source))->reject(fn ($f) => $f->getFilename() === '.gitignore');

        if ($files->isEmpty()) {
            return null;
        }

        $zip = new ZipArchive;
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), 'public/'.str_replace('\\', '/', $file->getRelativePathname()));
        }

        $zip->close();

        return $files->count();
    }

    private function prune(string $dir): int
    {
        $cutoff = now()->subDays((int) config('backup.keep_days'))->getTimestamp();
        $removed = 0;

        foreach (File::files($dir) as $file) {
            // Only files this command made; never anything else in the folder.
            if (preg_match('/^swa-(db|images)-\d{8}-\d{6}\.(sql|zip)$/', $file->getFilename()) && $file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    private function size(string $file): string
    {
        $bytes = filesize($file) ?: 0;

        return $bytes > 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
