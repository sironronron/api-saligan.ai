<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Which axis this term belongs to: the case-file category a
            // document is filed under, or the free tag a thread carries.
            $table->string('kind', 30);

            // Ownership is a three-state affair, expressed by which of the two
            // owner columns are filled:
            //   organization_id null + user_id null -> system, seeded, shared by everyone
            //   organization_id set               -> owned by the org, user_id records who made it
            //   user_id set, organization_id null -> personal, for members of no organization
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('slug', 60);
            $table->string('name', 60);

            // Doubles as the picker's hint text and as the description handed
            // to the classifier when it decides what a document is.
            $table->string('description')->nullable();

            // Coarse bucket the picker groups options under (Pleadings,
            // Evidence, Procedure...). Null for ungrouped custom terms.
            $table->string('group', 40)->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'organization_id']);
            $table->index(['kind', 'user_id']);
        });

        // Uniqueness has to be expressed as three partial indexes rather than
        // one composite unique. Postgres treats NULLs as distinct, so a plain
        // unique(kind, organization_id, user_id, slug) would happily accept a
        // second system label with the same slug — exactly the duplicate the
        // constraint exists to prevent.
        DB::statement('create unique index labels_system_slug_unique on labels (kind, slug) where organization_id is null and user_id is null');
        DB::statement('create unique index labels_organization_slug_unique on labels (kind, organization_id, slug) where organization_id is not null');
        DB::statement('create unique index labels_user_slug_unique on labels (kind, user_id, slug) where organization_id is null and user_id is not null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
