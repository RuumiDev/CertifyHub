<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('batch_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('identification_number')->nullable();
            $table->string('group_identifier')->nullable();
            $table->json('override_settings')->nullable();
            $table->enum('generation_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['batch_id', 'generation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
