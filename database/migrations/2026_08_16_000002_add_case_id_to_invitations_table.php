<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An invite sent from a case carries that case with it, so accepting the
     * invite lands the new member on the matter they were invited to work.
     * Without this the inline invite would seat someone in the organization
     * and leave them staring at an empty case list.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->uuid('case_id')->nullable()->after('organization_id');

            // The invite outlives the case being deleted; it just stops
            // carrying an assignment with it.
            $table->foreign('case_id')->references('id')->on('cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeign(['case_id']);
            $table->dropColumn('case_id');
        });
    }
};
