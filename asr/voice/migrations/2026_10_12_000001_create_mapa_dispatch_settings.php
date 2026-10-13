<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_dispatch_settings', function (Blueprint $t) {
            $t->uuid('company_uuid')->primary();
            $t->boolean('enabled')->default(false);
            $t->string('mode', 24)->default('all_live');
            $t->unsignedInteger('revision')->default(1);
            $t->uuid('updated_by')->nullable();
            $t->timestamp('last_tick_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mapa_dispatch_settings_audit', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('company_uuid');
            $t->uuid('user_uuid');
            $t->boolean('enabled');
            $t->unsignedInteger('revision');
            $t->timestamp('created_at');
            $t->index(['company_uuid', 'created_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('mapa_dispatch_settings_audit');
        Schema::dropIfExists('mapa_dispatch_settings');
    }
};
