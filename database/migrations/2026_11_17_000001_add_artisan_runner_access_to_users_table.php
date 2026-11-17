<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: on some live databases the column was applied
        // without a migrations-log record (e.g. a partial run or manual
        // cPanel change). Re-running must skip instead of failing.
        if (Schema::hasColumn('users', 'artisan_runner_access')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('artisan_runner_access')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'artisan_runner_access')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('artisan_runner_access');
        });
    }
};
