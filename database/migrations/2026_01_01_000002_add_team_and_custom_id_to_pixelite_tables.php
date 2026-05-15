<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — safe to run on both fresh and existing installations.
 * Columns are only added when they don't already exist.
 * Column types are driven by the pixelite config (set via pixelite:install).
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamIdFmt = config('pixelite.tracking.team_id.format', 'integer');
        $customFmt = config('pixelite.tracking.custom_id.format', 'string');

        Schema::table('visit_raws', function (Blueprint $table) use ($teamIdFmt, $customFmt): void {
            if (! Schema::hasColumn('visit_raws', 'team_id')) {
                match ($teamIdFmt) {
                    'uuid'  => $table->char('team_id', 36)->nullable()->after('user_id')->index(),
                    'ulid'  => $table->char('team_id', 26)->nullable()->after('user_id')->index(),
                    default => $table->unsignedBigInteger('team_id')->nullable()->after('user_id')->index(),
                };
            }
            if (! Schema::hasColumn('visit_raws', 'custom_id')) {
                match ($customFmt) {
                    'uuid'    => $table->char('custom_id', 36)->nullable()->after('session_id')->index(),
                    'ulid'    => $table->char('custom_id', 26)->nullable()->after('session_id')->index(),
                    'integer' => $table->unsignedBigInteger('custom_id')->nullable()->after('session_id')->index(),
                    default   => $table->string('custom_id', 255)->nullable()->after('session_id')->index(),
                };
            }
        });

        Schema::table('visits', function (Blueprint $table) use ($teamIdFmt, $customFmt): void {
            if (! Schema::hasColumn('visits', 'team_id')) {
                match ($teamIdFmt) {
                    'uuid'  => $table->char('team_id', 36)->nullable()->after('user_id')->index(),
                    'ulid'  => $table->char('team_id', 26)->nullable()->after('user_id')->index(),
                    default => $table->unsignedBigInteger('team_id')->nullable()->after('user_id')->index(),
                };
            }
            if (! Schema::hasColumn('visits', 'custom_id')) {
                match ($customFmt) {
                    'uuid'    => $table->char('custom_id', 36)->nullable()->after('session_id')->index(),
                    'ulid'    => $table->char('custom_id', 26)->nullable()->after('session_id')->index(),
                    'integer' => $table->unsignedBigInteger('custom_id')->nullable()->after('session_id')->index(),
                    default   => $table->string('custom_id', 255)->nullable()->after('session_id')->index(),
                };
            }
        });
    }

    public function down(): void
    {
        Schema::table('visit_raws', function (Blueprint $table): void {
            $cols = array_filter(['team_id', 'custom_id'], fn ($c) => Schema::hasColumn('visit_raws', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });

        Schema::table('visits', function (Blueprint $table): void {
            $cols = array_filter(['team_id', 'custom_id'], fn ($c) => Schema::hasColumn('visits', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
