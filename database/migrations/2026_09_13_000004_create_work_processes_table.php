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
        Schema::create('work_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('source')->default('manual');
            $table->string('marketplace_process_id')->nullable();
            $table->integer('recurrence_interval')->nullable();
            $table->string('recurrence_unit')->default('none');
            $table->string('due_mode')->nullable();
            $table->tinyInteger('due_day')->nullable();
            $table->integer('estimated_duration_days')->nullable();
            $table->string('competence_offset')->default('due_month');
            $table->integer('target_lead_days')->nullable();
            $table->boolean('cascade_execution')->default(false);
            $table->json('association_regimes')->nullable();
            $table->json('association_tag_ids')->nullable();
            $table->json('extra_client_ids')->nullable();
            $table->json('excluded_client_ids')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_processes');
    }
};
