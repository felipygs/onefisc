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
        Schema::create('work_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('work_process_id')->constrained('work_processes')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('work_process_task_definition_id')->nullable()->constrained('work_process_task_definitions')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('todo');
            $table->integer('position')->default(0);
            $table->string('priority')->default('medium');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('account_departments')->nullOnDelete();
            $table->string('competence', 7)->nullable();
            $table->date('start_at')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamps();
            // Idempotence guard for materialized rows. NULL definition ids
            // (ad-hoc tasks) compare as distinct in the unique index, so
            // ad-hoc dedupe stays app-layer (Task 2.3).
            $table->unique(['work_process_id', 'client_id', 'competence', 'work_process_task_definition_id'], 'work_tasks_materialization_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_tasks');
    }
};
