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
        Schema::create('work_process_task_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_process_id')->constrained('work_processes')->cascadeOnDelete();
            $table->string('title');
            $table->integer('position');
            $table->text('description')->nullable();
            $table->tinyInteger('due_day')->nullable();
            $table->string('competence_offset')->nullable();
            $table->string('priority')->default('medium');
            $table->foreignId('default_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('account_departments')->nullOnDelete();
            $table->boolean('requires_document')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_process_task_definitions');
    }
};
