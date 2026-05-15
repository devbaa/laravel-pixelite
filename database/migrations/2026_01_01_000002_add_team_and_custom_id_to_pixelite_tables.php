<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — safe to run on both fresh and existing installations.
 * Columns are only added when they don't already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_raws', function (Blueprint $table): void {
            if (! Schema::hasColumn('visit_raws', 'team_id')) {
                $table->unsignedBigInteger('team_id')->nullable()->after('user_id')->index();
            }
            if (! Schema::hasColumn('visit_raws', 'custom_id')) {
                $table->string('custom_id', 255)->nullable()->after('session_id')->index();
            }
        });

        Schema::table('visits', function (Blueprint $table): void {
            if (! Schema::hasColumn('visits', 'team_id')) {
                $table->unsignedBigInteger('team_id')->nullable()->after('user_id')->index();
            }
            if (! Schema::hasColumn('visits', 'custom_id')) {
                $table->string('custom_id', 255)->nullable()->after('session_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('visit_raws', function (Blueprint $table): void {
            $table->dropColumn(array_filter(['team_id', 'custom_id'], fn ($c) => Schema::hasColumn('visit_raws', $c)));
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn(array_filter(['team_id', 'custom_id'], fn ($c) => Schema::hasColumn('visits', $c)));
        });
    }
};
