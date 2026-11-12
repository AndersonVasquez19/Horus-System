<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            // Sin inferir el motor de registros históricos.
            $table->string('fuente', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table): void {
            $table->dropColumn('fuente');
        });
    }
};
