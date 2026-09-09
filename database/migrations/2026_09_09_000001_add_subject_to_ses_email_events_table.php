<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ses_email_events', function (Blueprint $table) {
            $table->string('subject', 998)->nullable()->after('recipient');
        });
    }

    public function down(): void
    {
        Schema::table('ses_email_events', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
