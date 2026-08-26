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
        Schema::create('slide_documents', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('path');
            // pending: caricato ma non ancora indicizzato | ingested: nel vector store | failed: errore in fase di ingestione
            $table->string('status')->default('pending');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slide_documents');
    }
};
