<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curso_publicaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curso_id')->unique()->constrained('cursos')->restrictOnDelete();
            $table->string('titulo', 120);
            $table->string('resumen', 500);
            $table->string('modalidad', 30);
            $table->string('horario', 200)->nullable();
            $table->string('inscripcion', 400)->nullable();
            $table->date('vigente_desde');
            $table->date('vigente_hasta');
            $table->boolean('publicada')->default(false);
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autorizado_at');
            $table->timestamps();
        });

        $permission = Permission::findOrCreate('cursos.publicar', 'web');
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('curso_publicaciones');
        Permission::query()->where('name', 'cursos.publicar')->where('guard_name', 'web')->get()
            ->each(fn (Permission $permission) => $permission->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
