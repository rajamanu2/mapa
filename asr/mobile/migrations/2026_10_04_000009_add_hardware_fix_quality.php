<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        foreach (['mapa_device_positions','mapa_device_position_history'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name) {
                if ($name === 'mapa_device_positions') $t->float('accuracy_m')->nullable()->change();
                else $t->double('accuracy_m')->nullable()->change();
                $t->string('source_protocol',32)->nullable();
                $t->boolean('gps_valid')->nullable();
                $t->unsignedTinyInteger('satellites')->nullable();
            });
        }
    }
    public function down(): void {
        // Unknown accuracy cannot be converted back to a required number without falsifying evidence.
        throw new RuntimeException('Hardware quality rollback requires a reviewed data migration.');
    }
};
