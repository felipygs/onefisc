<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotence guard for marketplace installs: one process per
     * (account, listing). Manual rows keep `marketplace_process_id` NULL,
     * which never collides under the unique index.
     */
    public function up(): void
    {
        Schema::table('work_processes', function (Blueprint $table) {
            $table->unique(['account_id', 'marketplace_process_id'], 'work_processes_account_marketplace_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_processes', function (Blueprint $table) {
            $table->dropUnique('work_processes_account_marketplace_unique');
        });
    }
};
