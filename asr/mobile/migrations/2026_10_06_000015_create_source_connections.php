<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_source_connections',function(Blueprint $t){
            $t->bigIncrements('id');$t->uuid('company_uuid');$t->string('dataset',20);$t->string('source_id',120);$t->uuid('native_uuid')->nullable();
            $t->boolean('active');$t->uuid('reviewed_by');$t->unsignedInteger('revision');$t->string('source_contract',40);$t->char('source_generation',64);$t->char('source_fingerprint',64)->nullable();$t->string('reason',500);$t->timestamps();
            $t->unique(['company_uuid','dataset','source_id'],'mapa_source_connection_source');$t->unique(['company_uuid','dataset','native_uuid'],'mapa_source_connection_native');
        });
        Schema::create('mapa_source_connection_audit',function(Blueprint $t){
            $t->bigIncrements('id');$t->uuid('company_uuid');$t->string('dataset',20);$t->string('source_id',120);$t->uuid('native_uuid')->nullable();$t->uuid('previous_native_uuid')->nullable();
            $t->uuid('reviewed_by');$t->string('action',10);$t->unsignedInteger('revision');$t->char('source_generation',64);$t->char('source_fingerprint',64)->nullable();$t->string('reason',500);$t->timestamp('created_at');
            $t->index(['company_uuid','dataset','source_id'],'mapa_source_audit_lookup');
        });
    }
    public function down(): void {Schema::dropIfExists('mapa_source_connection_audit');Schema::dropIfExists('mapa_source_connections');}
};
