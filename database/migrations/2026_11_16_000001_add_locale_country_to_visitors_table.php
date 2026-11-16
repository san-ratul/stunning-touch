<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            // Approximate country from the visitor's browser locale when no
            // edge geo header (CF-IPCountry) is available. Never exact.
            $table->string('locale_country', 5)->nullable()->after('visitor_hash');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn('locale_country');
        });
    }
};
