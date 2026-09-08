<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_email_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('sns_message_id');
            $table->string('ses_message_id', 255);
            $table->string('recipient', 320);
            $table->string('source', 320)->nullable();
            $table->string('event_type', 40);
            $table->timestamp('occurred_at');
            $table->text('detail')->nullable();
            $table->timestamps();
            $table->unique(['sns_message_id', 'recipient']);
            $table->index(['recipient', 'occurred_at']);
            $table->index(['ses_message_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_email_events');
    }
};
