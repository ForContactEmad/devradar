<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classification decisions. Many rows per tweet, by design.
 *
 * RE-ANALYSIS is the whole point of this table's shape. Every decision carries
 * the provider, model, model version and prompt version that produced it, so:
 *
 *   - a prompt change can be replayed against the labelled evaluation set and
 *     the two runs compared row by row;
 *   - silent model drift becomes visible instead of quietly degrading
 *     precision;
 *   - a past ranking stays explainable after the classifier has moved on.
 *
 * Old analyses are never overwritten. is_current marks the one in force, and a
 * partial unique index guarantees exactly one per tweet -- an ambiguity the
 * publishing stage would otherwise have to resolve at read time.
 *
 * cost_usd is recorded per analysis so AI spend and provider spend land in one
 * ledger, giving a true cost-per-published-project figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tweet_id')
                ->constrained('tweets')
                ->cascadeOnDelete();

            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('model_version', 64)->nullable();
            $table->string('prompt_version', 32);

            $table->boolean('is_launch')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('category', 32)->nullable();

            $table->string('extracted_name', 160)->nullable();
            $table->text('extracted_description')->nullable();

            // Validated against URLs present in the source post before being
            // written. A model-generated link is never trusted.
            $table->text('extracted_url')->nullable();

            // Free-form tags as returned. Resolved to technologies rows at
            // publish time; kept here as the unresolved original.
            $table->jsonb('technologies')->nullable();

            $table->jsonb('raw_response')->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);

            $table->boolean('is_current')->default(true);
            $table->timestampTz('analyzed_at')->useCurrent();

            $table->timestampsTz();

            $table->index(['tweet_id', 'analyzed_at'], 'ai_analyses_tweet_analyzed_index');
            $table->index(['is_launch', 'confidence'], 'ai_analyses_launch_confidence_index');
            $table->index(['prompt_version', 'model'], 'ai_analyses_prompt_model_index');
        });

        DB::statement("
            ALTER TABLE ai_analyses
            ADD CONSTRAINT ai_analyses_confidence_range
            CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1))
        ");

        // Exactly one current analysis per tweet.
        DB::statement("
            CREATE UNIQUE INDEX ai_analyses_one_current_per_tweet
            ON ai_analyses (tweet_id)
            WHERE is_current
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
    }
};
