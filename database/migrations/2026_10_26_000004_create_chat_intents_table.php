<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_intents', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->unique();
            $table->json('keywords');
            $table->text('respuesta');
            $table->integer('prioridad')->default(10);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_intents');
    }
};
