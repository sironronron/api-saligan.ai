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
        Schema::create('labelables', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('label_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('labelable');

            // Who put this label here, and how. A label the classifier guessed
            // may be replaced on a later pass; one a person chose never is.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('user');
            $table->float('confidence')->nullable();
            $table->timestamps();

            $table->unique(['label_id', 'labelable_type', 'labelable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('labelables');
    }
};
