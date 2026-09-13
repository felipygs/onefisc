<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track where each fiscal document came from: the national
     * distribution channel (DistDFe/ADN ciencia) or the national issuer
     * portal (NFS-e fallback). NULL means legacy rows persisted before
     * provenance was recorded.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('origin')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
