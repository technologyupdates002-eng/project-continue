<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTimestampsToStorageObjects extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('storage_objects')) {
            return;
        }

        Schema::table('storage_objects', function (Blueprint $table) {
            if (!Schema::hasColumn('storage_objects', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('storage_objects', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('storage_objects')) {
            return;
        }

        Schema::table('storage_objects', function (Blueprint $table) {
            if (Schema::hasColumn('storage_objects', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('storage_objects', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
}
