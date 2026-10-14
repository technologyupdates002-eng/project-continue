<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ImportFullSchema extends Migration
{
    /**
     * Run the migrations.
     * This migration applies the raw SQL from the schema.sql file.
     */
    public function up()
    {
        $candidates = [
            database_path('schema.sql'),
            base_path('database/schema.sql'),
        ];

        $path = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if (! $path) {
            throw new \RuntimeException('schema.sql not found. Checked: ' . implode(', ', $candidates));
        }

        $sql = file_get_contents($path);

        // Common sanitizations for legacy export quirks
        // Fix missing space in 'ON DELETESET NULL'
        $sql = preg_replace('/ON DELETE\s*SET\s*NULL/i', 'ON DELETE SET NULL', $sql);
        $sql = preg_replace('/ON DELETESET\s+NULL/i', 'ON DELETE SET NULL', $sql);

        // Replace DEFAULT ('value') -> DEFAULT 'value'
        $sql = preg_replace("/DEFAULT\s*\(\s*'([^']*)'\s*\)/i", "DEFAULT '$1'", $sql);
        // Replace DEFAULT (number) -> DEFAULT number
        $sql = preg_replace('/DEFAULT\s*\(\s*([0-9.+-]+)\s*\)/i', 'DEFAULT $1', $sql);

        // Normalize line endings and split statements
        $sql = str_replace("\r\n", "\n", $sql);
        $stmts = preg_split('/;\s*\n/', $sql);

        foreach ($stmts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || stripos($stmt, 'SET NAMES') === 0) {
                continue;
            }

            try {
                DB::statement($stmt);
            } catch (\Exception $e) {
                // Log the error to make debugging easier but continue processing
                // so that as many tables as possible are created.
                \Log::warning('schema import statement failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     * We do not implement a full rollback for the raw import.
     */
    public function down()
    {
        // Intentionally left empty. Rollback should be handled manually if needed.
    }
}
