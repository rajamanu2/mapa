<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('mapa_text_send_claims',function(Blueprint $t){$t->uuid('message_id')->primary();$t->timestamp('claimed_at');});
  Schema::create('mapa_text_audit',function(Blueprint $t){$t->bigIncrements('id');$t->string('company_uuid',36)->index();$t->string('user_uuid',36);$t->string('action',40);$t->string('record_id',80)->nullable();$t->timestamp('created_at');});
 }
 public function down(): void {Schema::dropIfExists('mapa_text_send_claims');Schema::dropIfExists('mapa_text_audit');}
};
