<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('donation_campaigns') && !Schema::hasColumn('donation_campaigns', 'total_collected')) {
            Schema::table('donation_campaigns', function (Blueprint $table) {
                $table->decimal('total_collected', 14, 2)->default(0)->after('allow_partial');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('donation_campaigns') && Schema::hasColumn('donation_campaigns', 'total_collected')) {
            Schema::table('donation_campaigns', function (Blueprint $table) {
                $table->dropColumn('total_collected');
            });
        }
    }
};
