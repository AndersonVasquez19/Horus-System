<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('telefono', 20)->nullable()->after('email');
            $table->json('notificacion_preferencias')->nullable()->after('telefono');
            $table->timestamp('bloqueado_hasta')->nullable()->after('jwt_version');
            $table->string('motivo_bloqueo', 255)->nullable()->after('bloqueado_hasta');
            $table->boolean('debe_cambiar_password')->default(false)->after('motivo_bloqueo');
            $table->text('two_factor_secret')->nullable()->after('debe_cambiar_password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
            $table->softDeletes();

            $table->index('bloqueado_hasta');
        });

        Schema::table('matricula_notifications', function (Blueprint $table): void {
            $table->string('provider_status', 30)->nullable()->after('provider_id');
            $table->timestamp('delivered_at')->nullable()->after('provider_status');
            $table->timestamp('bounced_at')->nullable()->after('delivered_at');
            $table->index('provider_id');
        });

        Schema::table('reclamacion_envios', function (Blueprint $table): void {
            $table->string('provider_status', 30)->nullable()->after('proveedor_id');
            $table->timestampTz('delivered_at')->nullable()->after('provider_status');
            $table->timestampTz('bounced_at')->nullable()->after('delivered_at');
            $table->index('proveedor_id');
        });

        Schema::create('avisos_externos', function (Blueprint $table): void {
            $table->id();
            $table->string('canal', 20);
            $table->string('tipo', 40);
            $table->string('clave_unica', 120)->unique();
            $table->uuid('delivery_key')->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('uncertain')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('estudiante_id')->nullable()->constrained('estudiantes')->nullOnDelete();
            $table->text('payload')->nullable();
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('dispatched_until')->nullable();
            $table->uuid('lease')->nullable();
            $table->string('provider_id', 100)->nullable()->index();
            $table->string('provider_status', 30)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('last_error', 40)->nullable();
            $table->timestamps();

            $table->index(['canal', 'status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_externos');

        Schema::table('reclamacion_envios', function (Blueprint $table): void {
            $table->dropIndex(['proveedor_id']);
            $table->dropColumn(['provider_status', 'delivered_at', 'bounced_at']);
        });

        Schema::table('matricula_notifications', function (Blueprint $table): void {
            $table->dropIndex(['provider_id']);
            $table->dropColumn(['provider_status', 'delivered_at', 'bounced_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['bloqueado_hasta']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'telefono',
                'notificacion_preferencias',
                'bloqueado_hasta',
                'motivo_bloqueo',
                'debe_cambiar_password',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};
