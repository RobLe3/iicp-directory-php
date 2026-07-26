<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

class OperatorReadiness
{
    public function __construct(private readonly Migrator $migrator) {}

    public function ready(): bool
    {
        try {
            DB::selectOne('SELECT 1');

            if (! $this->migrator->repositoryExists()) {
                return false;
            }

            $files = $this->migrator->getMigrationFiles(database_path('migrations'));
            $ran = $this->migrator->getRepository()->getRan();

            return array_diff(array_keys($files), $ran) === [];
        } catch (Throwable) {
            return false;
        }
    }
}
