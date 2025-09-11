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
        Schema::create('referers', function (Blueprint $table): void {
            $table->id();
            $table->string('raw', 1024)->nullable();
            $table->string('domain', 255)->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('hash', 32)->unique();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrers');
    }
};
