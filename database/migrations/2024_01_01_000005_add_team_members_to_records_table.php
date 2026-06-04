<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            // Store team member names as a JSON array e.g. ["Ahmad","Akmal","Abdullah"].
            // recipient_name remains for individual records; team records use team_members + group_identifier.
            $table->json('team_members')->nullable()->after('group_identifier');
            // Allow recipient_name to be null when the record represents a team group.
            $table->string('recipient_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn('team_members');
            $table->string('recipient_name')->nullable(false)->change();
        });
    }
};
