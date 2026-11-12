<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->unique()->constrained('matriculas')->cascadeOnDelete();
            $table->uuid('delivery_key')->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('uncertain')->default(false);
            $table->text('payload')->nullable(); // Cifrado con APP_KEY; nunca va en el job o log.
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('dispatched_until')->nullable();
            $table->uuid('lease')->nullable();
            $table->string('provider_id', 100)->nullable();
            $table->string('last_error', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_notifications');
    }
};
