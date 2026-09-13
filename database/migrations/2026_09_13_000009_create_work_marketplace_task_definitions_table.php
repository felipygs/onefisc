<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_marketplace_task_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_process_id')->constrained('work_marketplace_processes')->cascadeOnDelete();
            $table->string('title');
            $table->integer('position');
            $table->text('description')->nullable();
            $table->tinyInteger('due_day')->nullable();
            $table->string('competence_offset')->nullable();
            $table->string('priority')->default('medium');
            $table->boolean('requires_document')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_marketplace_task_definitions');
    }
};
