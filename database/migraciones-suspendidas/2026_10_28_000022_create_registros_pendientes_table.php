<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_pendientes', function (Blueprint $table): void {
            $table->id();
            $table->string('email_hash', 64)->unique();
            $table->text('email');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_pendientes');
    }
};
