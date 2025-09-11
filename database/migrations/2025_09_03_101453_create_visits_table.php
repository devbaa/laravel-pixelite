<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->index();
            $table->string('session_id', 64)->nullable()->index();
            $table->string('route_name', 255)->nullable();
            $table->json('route_params')->nullable();
            $table->binary('ip', 16)->nullable()->index();
            $table->string('country_code', 2)->nullable()->index();
            $table->boolean('device_category')->default(0)->index();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
