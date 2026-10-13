<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_maintenance_runs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->uuid('user_uuid');
            $t->string('action', 64); $t->string('status', 24); $t->text('snapshot');
            $t->text('result')->nullable(); $t->bigInteger('expires_at'); $t->timestamps();
            $t->index(['company_uuid', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('mapa_maintenance_runs'); }
};
