<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_mobile_handover_revisions',function(Blueprint $t){
            $t->bigIncrements('id');$t->uuid('company_uuid');$t->uuid('handover_id');$t->unsignedInteger('revision');
            $t->uuid('changed_by');$t->text('previous_encrypted');$t->timestamp('created_at');
            $t->unique(['handover_id','revision'],'mapa_handover_revision_unique');
        });
    }
    public function down(): void {Schema::dropIfExists('mapa_mobile_handover_revisions');}
};
