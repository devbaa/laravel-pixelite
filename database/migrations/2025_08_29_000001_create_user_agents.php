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
        Schema::create('user_agents', function (Blueprint $table): void {
            $table->id();
            $table->text('raw', 1024);
            $table->string('device_category', 16)->index()->nullable();
            $table->string('browser_name', 32)->nullable();
            $table->string('os_name', 16)->index()->nullable();
            $table->string('hash', 32)->unique();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_agents');
    }
};
