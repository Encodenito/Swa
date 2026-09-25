<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ChatbotFaq;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayMongo\WebhookSignature;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Pre-launch and day-to-day health checks. Used by `swa:preflight`, the admin System health page
 * and the staff dashboard warning. Makes no network calls and never returns secret values.
 */
class HealthCheckService
{
    public const HEARTBEAT_KEY = 'ops.scheduler_heartbeat';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Scheduler considered stopped after this many seconds without a heartbeat. */
    public const HEARTBEAT_STALE_SECONDS = 180;

    /** A queued job this old means no worker is running. */
    public const JOB_STALE_SECONDS = 600;

    /**
     * @return list<array{key: string, group: string, label: string, status: string, detail: string, fix: string|null}>
     */
    public function all(): array
    {
        return array_merge($this->configuration(), $this->operations());
    }

    /**
     * Things that break while the shop is running: scheduler, queue worker, failed jobs.
     *
     * @return list<array{key: string, group: string, label: string, status: string, detail: string, fix: string|null}>
     */
    public function operations(): array
    {
        $checks = [];

        $beat = Cache::get(self::HEARTBEAT_KEY);
        $age = $beat ? now()->timestamp - (int) $beat : null;
        $checks[] = $age !== null && $age <= self::HEARTBEAT_STALE_SECONDS
            ? $this->ok('scheduler', 'Operations', 'Scheduler', "Last ran {$age} seconds ago.")
            : $this->fail('scheduler', 'Operations', 'Scheduler', $age === null
                ? 'The scheduler has never run. Unpaid reservations are not expiring and reminders are not sent.'
                : 'Last ran '.intdiv($age, 60).' minutes ago. Unpaid reservations are not expiring.',
                'Run `php artisan schedule:work` (local) or the every-minute scheduled task (docs/DEPLOYMENT.md).');

        $oldestJob = DB::table('jobs')->min('created_at');
        $jobAge = $oldestJob ? now()->timestamp - (int) $oldestJob : null;
        $checks[] = $jobAge === null || $jobAge <= self::JOB_STALE_SECONDS
            ? $this->ok('queue', 'Operations', 'Background worker', $jobAge === null ? 'No jobs waiting.' : 'Jobs are being processed.')
            : $this->fail('queue', 'Operations', 'Background worker',
                'Jobs have been waiting '.intdiv($jobAge, 60).' minutes. Emails and refunds are not being sent.',
                'Start `php artisan queue:work` and keep it running (docs/DEPLOYMENT.md).');

        $failed = DB::table('failed_jobs')->count();
        $checks[] = $failed === 0
            ? $this->ok('failed_jobs', 'Operations', 'Failed background jobs', 'None.')
            : $this->warn('failed_jobs', 'Operations', 'Failed background jobs', "{$failed} job(s) failed (usually emails).",
                'Check `php artisan queue:failed`. Retry emails with `php artisan queue:retry <id>`. Never bulk-retry refunds; use Payments to review.');

        $waitingRefunds = Payment::query()->where('type', PaymentType::Refund)->where('status', PaymentStatus::RefundPending)->whereNull('refund_attempted_at')->count();
        if ($waitingRefunds > 0 && $jobAge !== null && $jobAge > self::JOB_STALE_SECONDS) {
            $checks[] = $this->fail('refunds_waiting', 'Operations', 'Refunds waiting to send', "{$waitingRefunds} refund(s) are waiting to be sent.", 'Start the background worker. Any still waiting 15 minutes later appear under Staff → Payments to review for an admin to reconcile.');
        }

        if (ChatbotFaq::query()->where('answer', 'like', '%(Admin: replace this answer%')->exists()) {
            $checks[] = $this->warn('faq_placeholder', 'Content', 'Chatbot shop hours', 'The "shop hours and address" answer is still the placeholder.', 'Edit it under Admin → Chatbot FAQ.');
        }

        return $checks;
    }

    /**
     * Launch configuration. Values that are secret are only described, never returned.
     *
     * @return list<array{key: string, group: string, label: string, status: string, detail: string, fix: string|null}>
     */
    public function configuration(): array
    {
        $production = app()->environment('production');
        $checks = [];

        // Application
        $checks[] = $production
            ? $this->ok('env', 'Application', 'Environment', 'production')
            : $this->warn('env', 'Application', 'Environment', 'APP_ENV is '.app()->environment().' (fine for testing, must be production when live).', 'Set APP_ENV=production on the live server.');

        $checks[] = config('app.debug') && $production
            ? $this->fail('debug', 'Application', 'Debug mode', 'APP_DEBUG is on: errors would show code and settings to customers.', 'Set APP_DEBUG=false.')
            : $this->status(! config('app.debug'), 'debug', 'Application', 'Debug mode', config('app.debug') ? 'On (local only).' : 'Off.', 'Set APP_DEBUG=false before going live.');

        $checks[] = $this->status(filled(config('app.key')), 'app_key', 'Application', 'Encryption key', filled(config('app.key')) ? 'Set.' : 'Missing.', 'Run `php artisan key:generate` once (never change it afterwards).', fail: true);

        $https = str_starts_with((string) config('app.url'), 'https://');
        $checks[] = $https
            ? $this->ok('url', 'Application', 'Site address', 'Uses https.')
            : ($production
                ? $this->fail('url', 'Application', 'Site address', 'APP_URL is not https: payments and logins need a secure address.', 'Set APP_URL to your https address.')
                : $this->warn('url', 'Application', 'Site address', 'APP_URL is not https (fine locally).', 'Use an https APP_URL when live.'));

        $secureCookie = (bool) config('session.secure');
        $checks[] = $secureCookie || ! $production
            ? $this->status($secureCookie, 'cookie', 'Application', 'Secure session cookie', $secureCookie ? 'On.' : 'Off (fine locally).', 'Set SESSION_SECURE_COOKIE=true when live.')
            : $this->fail('cookie', 'Application', 'Secure session cookie', 'Off: session cookies could travel over plain http.', 'Set SESSION_SECURE_COOKIE=true.');

        $checks[] = $this->status(config('app.timezone') === 'Asia/Manila', 'timezone', 'Application', 'Timezone', (string) config('app.timezone'), 'Set APP_TIMEZONE=Asia/Manila.', fail: true);

        // PayMongo
        $secret = (string) config('paymongo.secret_key');
        $public = (string) config('paymongo.public_key');
        $live = (bool) config('paymongo.livemode');
        $placeholder = in_array($secret, ['', 'sk_test_xxx'], true) || in_array($public, ['', 'pk_test_xxx'], true);

        $keyMode = str_starts_with($secret, 'sk_live_') ? 'live' : (str_starts_with($secret, 'sk_test_') ? 'test' : 'unknown');
        $publicMode = str_starts_with($public, 'pk_live_') ? 'live' : (str_starts_with($public, 'pk_test_') ? 'test' : 'unknown');

        $checks[] = match (true) {
            $placeholder => $this->fail('paymongo_keys', 'Payments', 'PayMongo keys', 'Still the .env.example placeholders.', 'Paste your PayMongo keys into .env.'),
            $keyMode === 'unknown' || $publicMode === 'unknown' => $this->fail('paymongo_keys', 'Payments', 'PayMongo keys', 'Keys are not in the sk_…/pk_… format.', 'Copy the keys again from the PayMongo dashboard.'),
            $keyMode !== $publicMode => $this->fail('paymongo_keys', 'Payments', 'PayMongo keys', "Secret key is {$keyMode} but public key is {$publicMode}.", 'Use both keys from the same mode.'),
            ($keyMode === 'live') !== $live => $this->fail('paymongo_keys', 'Payments', 'PayMongo keys', "Keys are {$keyMode} but PAYMONGO_LIVEMODE is ".($live ? 'true' : 'false').'.', 'Make PAYMONGO_LIVEMODE match the keys.'),
            $keyMode === 'test' && $production => $this->warn('paymongo_keys', 'Payments', 'PayMongo keys', 'Test keys on a production server: no real money will move.', 'Switch to live keys when ready (docs/DEPLOYMENT.md).'),
            default => $this->ok('paymongo_keys', 'Payments', 'PayMongo keys', ucfirst($keyMode).' mode, both keys match.'),
        };

        $signature = WebhookSignature::fromConfig();
        $webhookSecret = (string) config('paymongo.webhook_secret');
        $checks[] = match (true) {
            ! str_starts_with($webhookSecret, 'whsk_') || $webhookSecret === WebhookSignature::PLACEHOLDER => $production
                ? $this->fail('webhook_secret', 'Payments', 'Webhook secret', 'Missing or still the placeholder: payments would never be confirmed.', 'Register the webhook in PayMongo and paste its whsk_ secret.')
                : $this->warn('webhook_secret', 'Payments', 'Webhook secret', 'Placeholder (the local simulator still works).', 'Register the webhook during your tunnel test (README).'),
            ! $signature->hasUsableSecret() => $this->fail('webhook_secret', 'Payments', 'Webhook secret', 'Not usable.', 'Paste the whsk_ secret from the PayMongo webhook.'),
            default => $this->ok('webhook_secret', 'Payments', 'Webhook secret', 'Set.'),
        };

        // Email
        $mailer = (string) config('mail.default');
        $checks[] = match (true) {
            $mailer === 'resend' && blank(config('services.resend.key')) => $this->fail('mail', 'Email', 'Email sending', 'MAIL_MAILER is resend but RESEND_KEY is empty.', 'Set RESEND_KEY.'),
            $mailer === 'resend' => $this->ok('mail', 'Email', 'Email sending', 'Resend.'),
            $production => $this->fail('mail', 'Email', 'Email sending', "MAIL_MAILER is {$mailer}: customers get no emails.", 'Verify your domain in Resend, then set MAIL_MAILER=resend (README).'),
            default => $this->warn('mail', 'Email', 'Email sending', "MAIL_MAILER is {$mailer}: emails go to the log file (fine locally).", 'Switch to resend once your domain is verified.'),
        };

        $from = (string) config('mail.from.address');
        $checks[] = str_ends_with($from, '@example.com') || $from === ''
            ? ($production
                ? $this->fail('mail_from', 'Email', 'Sender address', 'Still example.com.', 'Set MAIL_FROM_ADDRESS to an address on your verified domain.')
                : $this->warn('mail_from', 'Email', 'Sender address', 'Still example.com (fine locally).', 'Set MAIL_FROM_ADDRESS on your verified domain.'))
            : $this->ok('mail_from', 'Email', 'Sender address', 'Set.');

        // Queue and storage
        $checks[] = $this->status(config('queue.default') === 'database', 'queue_driver', 'Server', 'Queue driver', (string) config('queue.default'), 'Set QUEUE_CONNECTION=database.', fail: true);

        // file_exists, not is_link/is_dir: Windows junctions (what storage:link makes on XAMPP) fail those.
        $linked = file_exists(public_path('storage'));
        $checks[] = $this->status($linked, 'storage_link', 'Server', 'Image storage link', $linked ? 'Present.' : 'Missing: item photos will not show.', 'Run `php artisan storage:link`.', fail: true);

        $writable = is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'));
        $checks[] = $this->status($writable, 'writable', 'Server', 'Writable folders', $writable ? 'storage and bootstrap/cache are writable.' : 'storage or bootstrap/cache is not writable.', 'Give the web server write access to storage/ and bootstrap/cache/.', fail: true);

        $checks[] = $this->webRoot($production);
        $checks[] = $this->lastBackup();

        // Database
        try {
            $pending = $this->pendingMigrations();
            $checks[] = $pending === 0
                ? $this->ok('migrations', 'Database', 'Database schema', 'Up to date.')
                : $this->fail('migrations', 'Database', 'Database schema', "{$pending} migration(s) not run.", 'Run `php artisan migrate --force`.');
            $checks[] = $this->status(Setting::query()->count() > 0, 'settings', 'Database', 'Shop settings', Setting::query()->count() > 0 ? 'Present.' : 'Missing.', 'Run `php artisan db:seed --class=SettingsSeeder`.', fail: true);

            $devAccounts = User::query()->where('email', 'like', '%@swa.test')->count();
            $checks[] = $devAccounts > 0 && $production
                ? $this->fail('dev_accounts', 'Database', 'Development accounts', "{$devAccounts} *@swa.test account(s) with the password \"password\" exist.", 'Delete or deactivate them before launch.')
                : $this->status($devAccounts === 0, 'dev_accounts', 'Database', 'Development accounts', $devAccounts === 0 ? 'None.' : "{$devAccounts} present (fine locally).", 'Remove *@swa.test accounts before launch.');
        } catch (Throwable) {
            $checks[] = $this->fail('database', 'Database', 'Database connection', 'Cannot connect to the database.', 'Check that MariaDB/MySQL is running and the DB_* values in .env.');
        }

        return $checks;
    }

    public function hasFailures(array $checks): bool
    {
        return collect($checks)->contains('status', self::FAIL);
    }

    /**
     * XAMPP serves all of htdocs by default, so a project inside it exposes .env and backups unless
     * the web server points at public/ (or the project-root .htaccess denies the rest).
     */
    private function webRoot(bool $production): array
    {
        $insideHtdocs = str_contains(strtolower(str_replace('\\', '/', base_path())), '/htdocs/');
        $denied = is_file(base_path('.htaccess')) && str_contains((string) file_get_contents(base_path('.htaccess')), 'Require all denied');
        $fix = 'Point Apache at the public folder, or move the project out of htdocs (docs/DEPLOYMENT.md, "Web server folder").';

        return match (true) {
            ! $insideHtdocs => $this->ok('web_root', 'Server', 'Web server folder', 'Project is outside htdocs. Make sure the web server serves only public/.'),
            ! $denied => $production
                ? $this->fail('web_root', 'Server', 'Web server folder', 'Project is inside htdocs without the deny .htaccess: .env and backups can be downloaded.', $fix)
                : $this->warn('web_root', 'Server', 'Web server folder', 'Project is inside htdocs without the deny .htaccess: .env and backups can be downloaded.', $fix),
            default => $this->warn('web_root', 'Server', 'Web server folder', 'Project is inside htdocs. The .htaccess blocks .env and backups, but only on Apache with .htaccess enabled.', $fix),
        };
    }

    private function lastBackup(): array
    {
        $dir = (string) config('backup.path');
        $newest = collect(is_dir($dir) ? File::files($dir) : [])
            ->filter(fn ($file) => preg_match('/^swa-db-\d{8}-\d{6}(-[a-z0-9]+)?\.sql$/', $file->getFilename()))
            ->max(fn ($file) => $file->getMTime());

        if ($newest === null) {
            return $this->warn('backup', 'Server', 'Last backup', 'No database backup found.', 'Run `php artisan swa:backup`, and keep the scheduler running (nightly at 02:00).');
        }

        $hours = (int) floor((time() - $newest) / 3600);

        return $hours > 26
            ? $this->warn('backup', 'Server', 'Last backup', "Newest database backup is {$hours} hours old.", 'Check the scheduler is running, then run `php artisan swa:backup`.')
            : $this->ok('backup', 'Server', 'Last backup', $hours === 0 ? 'Less than an hour ago.' : "{$hours} hour(s) ago.");
    }

    private function pendingMigrations(): int
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return count($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        }

        $ran = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

        return count(array_diff(array_keys($files), $ran));
    }

    private function status(bool $good, string $key, string $group, string $label, string $detail, string $fix, bool $fail = false): array
    {
        return $good ? $this->ok($key, $group, $label, $detail) : ($fail ? $this->fail($key, $group, $label, $detail, $fix) : $this->warn($key, $group, $label, $detail, $fix));
    }

    private function ok(string $key, string $group, string $label, string $detail): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'status' => self::OK, 'detail' => $detail, 'fix' => null];
    }

    private function warn(string $key, string $group, string $label, string $detail, ?string $fix): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'status' => self::WARN, 'detail' => $detail, 'fix' => $fix];
    }

    private function fail(string $key, string $group, string $label, string $detail, ?string $fix): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'status' => self::FAIL, 'detail' => $detail, 'fix' => $fix];
    }
}
