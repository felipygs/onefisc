<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System-actor audit events (e.g. automatic ciencia 210210 from the
     * queue, with no authenticated user) record NULL actor_user_id.
     * AuditLog::senderName() already renders a missing actor as 'Sistema'.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('actor_user_id')->nullable()->change();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('actor_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('actor_user_id')->nullable(false)->change();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('actor_user_id')->references('id')->on('users');
        });
    }
};
