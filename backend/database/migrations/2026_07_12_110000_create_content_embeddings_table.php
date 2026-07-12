<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embeddings of a publication's content nodes, for the AI tutor's retrieval.
 *
 * Stored as a plain jsonb vector rather than a pgvector column: retrieval is
 * always scoped to a single course's nodes (small N), so ranking happens in the
 * app. This keeps the schema portable to any Postgres; it can migrate to a
 * `vector` column with an HNSW index if scale ever demands. See
 * docs/12-ai-tutor.md.
 *
 * Keyed by publication (immutable-aligned) and rebuilt when a course republishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('publication_id')->constrained('course_publications')->cascadeOnDelete();

            // Not a foreign key: the snapshot node may not exist in course_nodes.
            $table->uuid('course_node_id');

            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->string('label');
            $table->text('text');
            $table->jsonb('embedding');           // list<float>
            $table->string('model')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['publication_id', 'course_node_id', 'chunk_index']);
            $table->index('publication_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_embeddings');
    }
};
