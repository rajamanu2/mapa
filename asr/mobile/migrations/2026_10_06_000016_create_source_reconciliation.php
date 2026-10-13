<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_source_reconciliation_cursors',function(Blueprint $t){
            $t->uuid('company_uuid')->primary();$t->char('head',64)->nullable();$t->timestamp('updated_at')->nullable();
        });
        Schema::create('mapa_source_reconciliation_events',function(Blueprint $t){
            $t->char('id',64)->primary();$t->uuid('company_uuid');$t->char('generation',64);
            $t->string('dataset',32);$t->string('source_id',120);$t->string('kind',32);
            $t->json('observation');$t->double('source_generated_at');$t->unsignedBigInteger('observed_at');
            $t->uuid('acknowledged_by')->nullable();$t->timestamp('acknowledged_at')->nullable();$t->timestamp('created_at');
            $t->index(['company_uuid','acknowledged_at','observed_at'],'mapa_source_review_queue');
        });
    }
    public function down(): void {
        Schema::dropIfExists('mapa_source_reconciliation_events');Schema::dropIfExists('mapa_source_reconciliation_cursors');
    }
};
