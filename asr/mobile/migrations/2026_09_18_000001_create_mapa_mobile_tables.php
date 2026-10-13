<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mapa_mobile_bindings', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->uuid('user_uuid');
            $t->string('role', 20); $t->uuid('driver_uuid')->nullable(); $t->uuid('place_uuid')->nullable();
            $t->boolean('enabled')->default(false); $t->unsignedInteger('revision')->default(1); $t->timestamps();
            $t->unique(['company_uuid','user_uuid']); $t->unique(['company_uuid','driver_uuid']);
        });
        Schema::create('mapa_mobile_sessions', function (Blueprint $t) {
            $t->string('token_hash', 64)->primary(); $t->uuid('binding_id'); $t->uuid('company_uuid'); $t->uuid('user_uuid');
            $t->unsignedInteger('binding_revision'); $t->string('credential_hash',64); $t->string('scope_hash',64); $t->string('device_name',100);
            $t->unsignedBigInteger('expires_at'); $t->unsignedBigInteger('revoked_at')->nullable(); $t->timestamps();
            $t->index(['binding_id','expires_at']);
        });
        Schema::create('mapa_mobile_receipts', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->uuid('company_uuid'); $t->uuid('user_uuid'); $t->string('action_id',100);
            $t->string('request_hash',64); $t->json('result'); $t->timestamp('created_at');
            $t->unique(['company_uuid','user_uuid','action_id'], 'mapa_receipt_action_unique');
        });
        Schema::create('mapa_mobile_order_states', function (Blueprint $t) {
            $t->uuid('order_uuid')->primary(); $t->uuid('company_uuid'); $t->uuid('driver_uuid');
            $t->uuid('acknowledged_by'); $t->unsignedBigInteger('acknowledged_at');
            $t->string('assignment_hash',64); $t->timestamps();
        });
        Schema::create('mapa_mobile_driver_states', function (Blueprint $t) {
            $t->uuid('driver_uuid')->primary(); $t->uuid('company_uuid'); $t->unsignedBigInteger('recorded_at')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void
    {
        // Destructive rollback must be an explicit operator action; never invoked by module boot.
        foreach (['mapa_mobile_driver_states','mapa_mobile_order_states','mapa_mobile_receipts','mapa_mobile_sessions','mapa_mobile_bindings'] as $table) Schema::dropIfExists($table);
    }
};
