<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userIdFmt  = config('pixelite.tracking.user_id.format', 'integer');
        $teamIdFmt  = config('pixelite.tracking.team_id.format', 'integer');
        $customFmt  = config('pixelite.tracking.custom_id.format', 'string');

        Schema::create('visit_raws', function (Blueprint $table) use ($userIdFmt, $teamIdFmt, $customFmt): void {
            $table->id();

            match ($userIdFmt) {
                'uuid'  => $table->char('user_id', 36)->nullable()->index(),
                'ulid'  => $table->char('user_id', 26)->nullable()->index(),
                default => $table->unsignedBigInteger('user_id')->nullable()->index(),
            };

            match ($teamIdFmt) {
                'uuid'  => $table->char('team_id', 36)->nullable()->index(),
                'ulid'  => $table->char('team_id', 26)->nullable()->index(),
                default => $table->unsignedBigInteger('team_id')->nullable()->index(),
            };

            $table->string('session_id')->nullable()->index();

            match ($customFmt) {
                'uuid'    => $table->char('custom_id', 36)->nullable()->index(),
                'ulid'    => $table->char('custom_id', 26)->nullable()->index(),
                'integer' => $table->unsignedBigInteger('custom_id')->nullable()->index(),
                default   => $table->string('custom_id', 255)->nullable()->index(),
            };

            $table->string('route_name')->nullable();
            $table->json('route_params')->nullable();
            $table->binary('ip', 16)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->json('payload_js')->nullable();
            $table->timestamp('created_at')->index();
            $table->unsignedSmallInteger('total_time')->nullable();
            $table->index('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_raws');
    }
};
