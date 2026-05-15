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

        Schema::create('visits', function (Blueprint $table) use ($userIdFmt, $teamIdFmt, $customFmt): void {
            $table->id();

            // No FK constraint — analytics tables don't need referential integrity
            // and FK types must match the users/teams table PK format.
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

            $table->string('session_id', 64)->nullable()->index();

            match ($customFmt) {
                'uuid'    => $table->char('custom_id', 36)->nullable()->index(),
                'ulid'    => $table->char('custom_id', 26)->nullable()->index(),
                'integer' => $table->unsignedBigInteger('custom_id')->nullable()->index(),
                default   => $table->string('custom_id', 255)->nullable()->index(),
            };

            $table->string('route_name', 255)->nullable();
            $table->json('route_params')->nullable();
            $table->binary('ip', 16)->nullable()->index();
            $table->string('country_code', 2)->nullable()->index();
            $table->string('device_category', 16)->nullable()->index();
            $table->string('os_name', 16)->index()->nullable();
            $table->string('referer_domain', 255)->index()->nullable();
            $table->foreignId('geo_id')->nullable()->constrained('geos');
            $table->foreignId('user_agent_id')->nullable()->constrained('user_agents');
            $table->foreignId('referer_id')->nullable()->constrained('referers');
            $table->foreignId('utm_id')->nullable()->constrained('utms');
            $table->foreignId('click_id')->nullable()->constrained('click_ids');
            $table->foreignId('screen_id')->nullable()->constrained('screens');
            $table->integer('timezone')->nullable();
            $table->string('locale', 10)->nullable();
            $table->json('payload')->nullable();
            $table->json('payload_js')->nullable();
            $table->timestamp('created_at');
            $table->unsignedSmallInteger('total_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
