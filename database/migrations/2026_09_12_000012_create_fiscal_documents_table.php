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
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('family');
            $table->string('doc_type');
            $table->string('number')->nullable();
            $table->string('series')->nullable();
            $table->string('key', 44)->nullable();
            $table->boolean('derived_from_key')->default(false);
            $table->dateTime('emission_at')->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('issuer_tax_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_tax_id')->nullable();
            $table->string('status')->nullable();
            $table->boolean('has_xml')->default(false);
            $table->boolean('has_danfe')->default(false);
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->unique(['client_id', 'key']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
