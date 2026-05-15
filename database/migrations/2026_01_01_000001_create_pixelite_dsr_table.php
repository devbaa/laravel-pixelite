<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Subject Request audit log.
 * Records every deletion (Art.17) and export (Art.20) operation for compliance evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixelite_dsr', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);              // deletion | export | opt_out
            $table->string('identifier', 255);       // the actual value (user_id, session_id …)
            $table->string('identifier_type', 32);   // user_id | session_id | email
            $table->string('status', 16)->default('completed');
            $table->unsignedInteger('records_affected')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();

            $table->index(['type', 'identifier_type', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixelite_dsr');
    }
};
