<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_generation_continuations')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->dropColumn('max_generation_continuations');
        });
    }
};
