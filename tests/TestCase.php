<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a *_test database, before
     * RefreshDatabase gets a chance to migrate (e.g. after a stale config:cache).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests must run on a *_test database, got [{$database}]. Run `php artisan config:clear`.");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Page tests must not depend on a built frontend.
        $this->withoutVite();
    }
}
