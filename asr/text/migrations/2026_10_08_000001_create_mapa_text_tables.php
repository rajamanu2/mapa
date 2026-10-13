<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('mapa_text_settings',function(Blueprint $t){$t->string('company_uuid',36)->primary();$t->boolean('enabled')->default(false);$t->string('sender',24)->default('');$t->string('phone_id',80)->nullable()->unique();$t->string('template',120)->default('mapa_call_update');$t->string('language',16)->default('en');$t->timestamps();});
  Schema::create('mapa_text_conversations',function(Blueprint $t){$t->uuid('id')->primary();$t->string('company_uuid',36)->index();$t->string('call_id',36);$t->string('recipient',24);$t->string('state',8)->default('open');$t->text('tracking_url');$t->string('consented_by',36);$t->timestamp('consented_at');$t->timestamps();$t->unique(['company_uuid','call_id']);});
  Schema::create('mapa_text_messages',function(Blueprint $t){$t->uuid('id')->primary();$t->string('company_uuid',36)->index();$t->uuid('conversation_id')->index();$t->string('event_key',100);$t->string('direction',8)->default('out');$t->string('recipient',24);$t->string('sender',24);$t->string('phone_id',80);$t->string('template',120)->nullable();$t->string('language',16)->nullable();$t->text('body');$t->text('tracking_url')->nullable();$t->string('status',16)->default('queued');$t->string('provider_id',200)->nullable()->index();$t->string('error',200)->nullable();$t->timestamps();$t->unique(['conversation_id','event_key']);$t->unique(['phone_id','provider_id']);});
  Schema::create('mapa_text_receipts',function(Blueprint $t){$t->string('id',64)->primary();$t->string('phone_id',80);$t->string('provider_id',200);$t->string('status',16);$t->string('error',100)->nullable();$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('mapa_text_receipts');Schema::dropIfExists('mapa_text_messages');Schema::dropIfExists('mapa_text_conversations');Schema::dropIfExists('mapa_text_settings');}
};
