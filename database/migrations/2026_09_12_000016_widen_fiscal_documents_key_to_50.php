<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen fiscal_documents.key from 44 to 50 digits BEFORE any NFS-e
     * persistence: the national NFS-e access key is 50 digits and would
     * otherwise be truncated/rejected at the database layer (task 4.1).
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('key', 50)->nullable()->change();
        });
    }

    /**
     * Irreversible by design: narrowing back to 44 would truncate stored
     * 50-digit national NFS-e keys, so down() is a deliberate no-op (still
     * runs cleanly under migrate:rollback).
     */
    public function down(): void
    {
        // No-op: see above.
    }
};
