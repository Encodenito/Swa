<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Daily backup without a package: a mysqldump of the shop database plus a zip of uploaded images.
 * The database password goes to mysqldump through the MYSQL_PWD environment variable, never on
 * the command line (visible to other processes) or in logs. `.env` is never included.
 *
 * Backups hold every customer's data, so the folder must never be reachable from the web: the
 * path is refused under public/, and a deny .htaccess is written into it for Apache/XAMPP.
 */
class Backup extends Command
{
    protected $signature = 'swa:backup';

    protected $description = 'Back up the database and uploaded images, and delete old backups';

    /** Written into the backup folder so Apache refuses to serve it even when htdocs is the web root. */
    public const DENY_HTACCESS = "# Backups hold customer data: never serve this folder.\nRequire all denied\n";

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));
        $dir = rtrim((string) config('backup.path'), '/\\');

        if ($problem = $this->unsafePath($dir)) {
            $this->error("Backup folder refused: {$problem}");

            return self::FAILURE;
        }

        // Only plain database names: the name is the last mysqldump argument and must not look like an option.
        if (! preg_match('/^[A-Za-z0-9_$]+$/', (string) $connection['database'])) {
            $this->error('Backup refused: the database name may only contain letters, digits, _ and $.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($dir, 0700);
        @chmod($dir, 0700);
        File::put($dir.'/.htaccess', self::DENY_HTACCESS);

        // A random suffix makes the file names unguessable, as a second layer behind the deny rules.
        $stamp = now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
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

        @chmod($sqlFile, 0600);

        try {
            $images = $this->zipImages($zipFile);
        } catch (\RuntimeException $e) {
            File::delete($zipFile);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Database: '.basename($sqlFile).' ('.$this->size($sqlFile).')');
        $this->info($images === null ? 'Images: none to back up.' : "Images: {$images} file(s) in ".basename($zipFile).' ('.$this->size($zipFile).')');

        $removed = $this->prune($dir, [basename($sqlFile), basename($zipFile)]);
        $this->info("Removed {$removed} backup file(s) older than ".config('backup.keep_days').' days.');
        $this->line('Copy the backup folder to a second place: '.$dir);

        return self::SUCCESS;
    }

    /** Returns why the folder is unsafe, or null when it is fine. */
    private function unsafePath(string $dir): ?string
    {
        $normalise = fn (string $path) => strtolower(rtrim(str_replace('\\', '/', $path), '/')).'/';

        if ($dir === '' || ! preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $dir)) {
            return 'BACKUP_PATH must be an absolute path.';
        }

        if (str_starts_with($normalise($dir), $normalise(public_path()))) {
            return 'it is inside the public folder, so anyone could download it. Use storage/app/backups or a folder outside the website.';
        }

        return null;
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

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Image backup failed: could not create '.basename($zipFile).'.');
        }

        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), 'public/'.str_replace('\\', '/', $file->getRelativePathname()));
        }

        if (! $zip->close()) {
            throw new \RuntimeException('Image backup failed: could not write '.basename($zipFile).'.');
        }

        @chmod($zipFile, 0600);

        return $files->count();
    }

    /** @param  list<string>  $keep  this run's files, never deleted whatever the clock says */
    private function prune(string $dir, array $keep): int
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.keep_days')))->getTimestamp();
        $removed = 0;

        foreach (File::files($dir) as $file) {
            // Only files this command made; never anything else in the folder.
            if (in_array($file->getFilename(), $keep, true)
                || ! preg_match('/^swa-(db|images)-\d{8}-\d{6}(-[a-z0-9]+)?\.(sql|zip)$/', $file->getFilename())) {
                continue;
            }

            if ($file->getMTime() < $cutoff) {
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
