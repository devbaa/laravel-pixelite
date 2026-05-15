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
        Schema::create('visit_raws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('custom_id', 255)->nullable()->index();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_raws');
    }
};
