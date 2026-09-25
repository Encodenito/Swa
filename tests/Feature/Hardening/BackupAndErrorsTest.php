<?php

namespace Tests\Feature\Hardening;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class BackupAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups');
        File::deleteDirectory($this->dir);

        $fake = PHP_OS_FAMILY === 'Windows' ? base_path('tests/fixtures/fake-mysqldump.bat') : base_path('tests/fixtures/fake-mysqldump.php');
        config(['backup.path' => $this->dir, 'backup.mysqldump' => $fake, 'backup.keep_days' => 14]);

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($fake, 0755);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_backup_writes_a_dump_without_the_password_on_the_command_line(): void
    {
        config(['database.connections.'.config('database.default').'.password' => 'S3cretPw!']);

        $this->artisan('swa:backup')->assertSuccessful();

        $dump = collect(File::files($this->dir))->first(fn ($f) => str_ends_with($f->getFilename(), '.sql'));
        $this->assertNotNull($dump);

        $content = File::get($dump->getPathname());
        $this->assertStringContainsString('--single-transaction', $content);
        $this->assertStringContainsString('pwd_env: set', $content);
        $this->assertStringNotContainsString('S3cretPw!', $content);
    }

    public function test_old_backups_are_pruned_and_unrelated_files_are_kept(): void
    {
        File::ensureDirectoryExists($this->dir);
        $old = "{$this->dir}/swa-db-20200101-010101.sql";
        $unrelated = "{$this->dir}/notes.txt";
        File::put($old, 'old');
        File::put($unrelated, 'keep me');
        touch($old, now()->subDays(30)->getTimestamp());
        touch($unrelated, now()->subDays(30)->getTimestamp());

        $this->artisan('swa:backup')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($unrelated);
    }

    public function test_a_failed_dump_reports_failure_and_leaves_no_empty_file(): void
    {
        config(['backup.mysqldump' => base_path('tests/fixtures/does-not-exist.exe')]);

        $this->artisan('swa:backup')->expectsOutputToContain('Database backup failed')->assertFailed();

        $this->assertSame([], collect(File::files($this->dir))->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql'))->all());
    }

    public function test_backups_never_include_the_env_file(): void
    {
        $this->artisan('swa:backup')->assertSuccessful();

        foreach (File::files($this->dir) as $file) {
            $this->assertStringNotContainsString('APP_KEY=', File::get($file->getPathname()));
        }
    }

    public function test_backup_folder_gets_a_deny_htaccess_and_unguessable_names(): void
    {
        $this->artisan('swa:backup')->assertSuccessful();

        $this->assertStringContainsString('Require all denied', File::get("{$this->dir}/.htaccess"));
        $dump = collect(File::files($this->dir))->first(fn ($f) => str_ends_with($f->getFilename(), '.sql'));
        $this->assertMatchesRegularExpression('/^swa-db-\d{8}-\d{6}-[a-z0-9]{8}\.sql$/', $dump->getFilename());
    }

    public function test_backup_refuses_a_folder_inside_public(): void
    {
        config(['backup.path' => public_path('backups')]);

        $this->artisan('swa:backup')->expectsOutputToContain('inside the public folder')->assertFailed();

        $this->assertDirectoryDoesNotExist(public_path('backups'));
    }

    public function test_backup_refuses_a_relative_folder(): void
    {
        config(['backup.path' => 'backups']);

        $this->artisan('swa:backup')->expectsOutputToContain('absolute path')->assertFailed();
    }

    public function test_backup_refuses_an_odd_database_name(): void
    {
        config(['database.connections.'.config('database.default').'.database' => '--all-databases']);

        $this->artisan('swa:backup')->expectsOutputToContain('database name')->assertFailed();
    }

    public function test_a_zero_keep_days_never_deletes_the_backup_just_made(): void
    {
        config(['backup.keep_days' => 0]);

        $this->artisan('swa:backup')->assertSuccessful();

        $this->assertTrue(collect(File::files($this->dir))->contains(fn ($f) => str_ends_with($f->getFilename(), '.sql')));
    }

    public function test_an_expired_session_redirects_back_with_a_message(): void
    {
        Route::post('/_expired', fn () => abort(419))->middleware('web');

        $this->from('/items')->post('/_expired')
            ->assertStatus(303)
            ->assertRedirect('/items')
            ->assertSessionHas('error');
    }

    public function test_maintenance_page_keeps_retry_after(): void
    {
        config(['app.debug' => false]);
        Route::get('/_down', fn () => throw new ServiceUnavailableHttpException(60))->middleware('web');

        $this->get('/_down')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertInertia(fn ($page) => $page->component('Error')->where('status', 503));
    }

    public function test_friendly_error_pages_replace_the_debug_screen_in_production(): void
    {
        config(['app.debug' => false]);
        Route::get('/_boom', fn () => throw new \RuntimeException('secret internals'))->middleware('web');

        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));

        $boom = $this->get('/_boom')->assertStatus(500);
        $boom->assertInertia(fn ($page) => $page->component('Error')->where('status', 500));
        $this->assertStringNotContainsString('secret internals', $boom->getContent());
    }

    public function test_json_errors_stay_json(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/this-page-does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_hsts_only_on_secure_production_requests(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        app()->detectEnvironment(fn () => 'production');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }
}
