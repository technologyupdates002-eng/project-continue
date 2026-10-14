<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function migrateDatabases()
    {
        $this->artisan('db:wipe', ['--force' => true]);

        $this->artisan('migrate:install');

        $migrator = $this->app->make('migrator');
        $migrator->run([database_path('migrations/2026_08_24_120000_import_full_schema.php')]);

        $migrations = $migrator->getMigrationFiles(database_path('migrations'));
        foreach ($migrations as $name => $file) {
            if ($name !== '2026_08_24_120000_import_full_schema' && $name !== '2026_09_18_160000_create_financial_replacement_infrastructure') {
                $migrator->getRepository()->log($name, 1);
            }
        }

        $migrator->run([database_path('migrations/2026_09_18_160000_create_financial_replacement_infrastructure.php')]);
    }
}
