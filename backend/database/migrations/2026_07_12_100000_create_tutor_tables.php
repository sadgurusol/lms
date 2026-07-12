<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI tutor's conversations and messages.
 *
 * A conversation is scoped to one course and pinned to the publication it began
 * against, so its grounding is stable even if the course is later revised. See
 * docs/12-ai-tutor.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('publication_id')->constrained('course_publications')->cascadeOnDelete();

            // The client context the conversation was held under, for attribution
            // and per-client policy — from the session token, never a parameter.
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
        });

        Schema::create('tutor_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('tutor_conversations')->cascadeOnDelete();
            $table->string('role');                          // 'user' | 'assistant'
            $table->text('content');

            // Which content nodes grounded an assistant reply: [{id,label}, …].
            $table->jsonb('citations')->default(DB::raw("'[]'::jsonb"));

            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
        });

        DB::statement("ALTER TABLE tutor_messages ADD CONSTRAINT tutor_messages_role_check
            CHECK (role IN ('user','assistant'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_messages');
        Schema::dropIfExists('tutor_conversations');
    }
};
