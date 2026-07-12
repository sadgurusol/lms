<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2C billing.
 *
 * Money is stored as an integer count of minor units (paise, cents). A float
 * price will, eventually and unpredictably, charge someone ₹498.99999997.
 *
 * Entitlement lives on the `user`, not on the platform: a subscription bought on
 * the web must unlock the mobile app, and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->integer('price_minor');
            $table->char('currency', 3);
            $table->string('interval');
            $table->integer('trial_days')->default(0);
            $table->string('provider_ref')->nullable();      // the plan id at the provider
            $table->string('status')->default('active');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE plans ALTER COLUMN code TYPE citext');
        DB::statement('CREATE UNIQUE INDEX plans_code_unique ON plans (code)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_check CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_trial_check CHECK (trial_days >= 0)');
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_interval_check
            CHECK (interval IN ('month','year','one_time'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check
            CHECK (status IN ('active','retired'))");

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();

            $table->string('provider');
            $table->string('provider_sub_id')->nullable();

            $table->string('status');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            // The provider's clock for the last event we applied. Webhooks arrive
            // out of order; a late `payment.failed` must not override a fresh
            // `payment.captured`.
            $table->timestamp('provider_event_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_provider_check
            CHECK (provider IN ('razorpay','stripe','apple','google'))");
        // `pending` is a subscription opened at the provider but not yet paid
        // for. It entitles nothing. Without it, the row cannot exist before the
        // activation webhook — and the webhook would arrive with nothing to
        // apply it to.
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check
            CHECK (status IN ('pending','trialing','active','past_due','canceled','expired'))");

        DB::statement('CREATE UNIQUE INDEX subscriptions_provider_sub_unique
            ON subscriptions (provider, provider_sub_id) WHERE provider_sub_id IS NOT NULL');

        // One live subscription per user per plan. Two would double-bill and
        // make "which one entitles them?" unanswerable.
        //
        // `pending` is excluded on purpose: a learner who abandons a checkout
        // and starts another must not be blocked by the row from the first.
        DB::statement("CREATE UNIQUE INDEX one_live_subscription_per_plan
            ON subscriptions (user_id, plan_id)
            WHERE status IN ('trialing','active','past_due')");

        Schema::create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->integer('amount_minor');
            $table->char('currency', 3);
            $table->string('provider');
            $table->string('provider_ref')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
        });

        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_amount_check
            CHECK (amount_minor >= 0)');
        DB::statement('CREATE UNIQUE INDEX purchases_provider_ref_unique
            ON purchases (provider, provider_ref) WHERE provider_ref IS NOT NULL');

        /*
         * Every webhook, stored before it is processed.
         *
         * Providers retry. The unique index makes a duplicate delivery a no-op,
         * and keeping the raw payload means a bug in the handler can be replayed
         * rather than reconstructed from a support ticket.
         */
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->string('type');
            $table->jsonb('payload');
            $table->timestamp('occurred_at')->nullable();     // the provider's clock
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
