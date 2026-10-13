<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mapa_voice_calls',function(Blueprint $t){
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->string('provider',20);
            $t->string('provider_call_id',128); $t->string('account_sid',128)->nullable();
            $t->boolean('demo')->default(false); $t->string('state',20)->default('needs_review');
            $t->string('provider_status',20)->default('active'); $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('last_sequence')->default(0); $t->string('caller',40)->nullable(); $t->string('called',40)->nullable();
            $t->text('transcript')->nullable(); $t->json('draft')->nullable(); $t->json('review')->nullable();
            $t->uuid('reviewed_by')->nullable(); $t->timestamp('reviewed_at')->nullable();
            $t->uuid('order_uuid')->nullable()->unique(); $t->text('dismiss_reason')->nullable(); $t->timestamps();
            $t->unique(['company_uuid','provider','provider_call_id'],'mapa_voice_provider_call_unique');
            $t->index(['company_uuid','state','updated_at']);
        });
        Schema::create('mapa_voice_events',function(Blueprint $t){
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->uuid('call_id');
            $t->unsignedBigInteger('sequence'); $t->string('event_type',30); $t->string('request_hash',64);
            $t->timestamp('occurred_at'); $t->boolean('applied'); $t->timestamps();
            $t->unique(['call_id','sequence'],'mapa_voice_call_sequence_unique');
        });
        Schema::create('mapa_voice_receipts',function(Blueprint $t){
            $t->bigIncrements('id'); $t->uuid('company_uuid'); $t->uuid('user_uuid'); $t->uuid('action_id');
            $t->string('request_hash',64); $t->json('result'); $t->timestamp('created_at');
            $t->unique(['company_uuid','user_uuid','action_id'],'mapa_voice_action_unique');
        });
        Schema::create('mapa_voice_audit',function(Blueprint $t){
            $t->bigIncrements('id'); $t->uuid('company_uuid'); $t->uuid('call_id'); $t->uuid('user_uuid')->nullable();
            $t->string('action',30); $t->unsignedInteger('revision'); $t->json('details')->nullable(); $t->timestamp('created_at');
            $t->index(['call_id','created_at']);
        });
    }
    public function down(): void
    {
        foreach(['mapa_voice_audit','mapa_voice_receipts','mapa_voice_events','mapa_voice_calls'] as $t) Schema::dropIfExists($t);
    }
};
