<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Free', 'Starter', 'Pro', 'Teams', 'Enterprise']);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'price_monthly' => fake()->randomElement([0, 1200, 2900, 7900, 0]),
            'price_yearly' => fake()->randomElement([0, 9900, 24900, 69900, 0]),
            'limits' => [
                'credits_monthly' => 1000,
                'members' => 1,
                'active_workflows' => 5,
                'max_execution_time_seconds' => 30,
                'execution_log_retention_days' => 3,
                'min_schedule_interval_minutes' => null,
                'api_rate_limit_per_minute' => 30,
            ],
            'features' => [
                'webhook_triggers' => false,
                'schedule_triggers' => false,
                'import_export' => false,
                'custom_variables' => false,
                'ai_generation' => false,
                'ai_autofix' => false,
                'deterministic_replay' => false,
                'execution_debugger' => false,
                'priority_execution' => false,
                'environments' => false,
                'approval_workflows' => false,
                'connector_metrics' => false,
                'overage_protection' => false,
                'audit_logs' => false,
                'sso_saml' => false,
                'annual_rollover' => false,
                'credit_packs' => false,
            ],
            'stripe_product_id' => null,
            'stripe_prices' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Configure the plan as the Free tier.
     */
    public function free(): static
    {
        return $this->state(fn () => [
            'name' => 'Free',
            'slug' => 'free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'limits' => [
                'credits_monthly' => 1000,
                'members' => 1,
                'active_workflows' => 5,
                'max_execution_time_seconds' => 30,
                'execution_log_retention_days' => 3,
                'min_schedule_interval_minutes' => null,
                'api_rate_limit_per_minute' => 30,
            ],
            'features' => [
                'webhook_triggers' => false,
                'schedule_triggers' => false,
                'import_export' => false,
                'custom_variables' => false,
                'ai_generation' => false,
                'ai_autofix' => false,
                'deterministic_replay' => false,
                'execution_debugger' => false,
                'priority_execution' => false,
                'environments' => false,
                'approval_workflows' => false,
                'connector_metrics' => false,
                'overage_protection' => false,
                'audit_logs' => false,
                'sso_saml' => false,
                'annual_rollover' => false,
                'credit_packs' => false,
            ],
            'sort_order' => 0,
        ]);
    }

    /**
     * Configure the plan as the Starter tier.
     */
    public function starter(): static
    {
        return $this->state(fn () => [
            'name' => 'Starter',
            'slug' => 'starter',
            'price_monthly' => 1200,
            'price_yearly' => 9900,
            'limits' => [
                'credits_monthly' => 10000,
                'members' => 3,
                'active_workflows' => 20,
                'max_execution_time_seconds' => 120,
                'execution_log_retention_days' => 7,
                'min_schedule_interval_minutes' => 15,
                'api_rate_limit_per_minute' => 60,
            ],
            'features' => [
                'webhook_triggers' => true,
                'schedule_triggers' => true,
                'import_export' => true,
                'custom_variables' => false,
                'ai_generation' => false,
                'ai_autofix' => false,
                'deterministic_replay' => false,
                'execution_debugger' => false,
                'priority_execution' => false,
                'environments' => false,
                'approval_workflows' => false,
                'connector_metrics' => false,
                'overage_protection' => false,
                'audit_logs' => false,
                'sso_saml' => false,
                'annual_rollover' => false,
                'credit_packs' => true,
            ],
            'sort_order' => 1,
        ]);
    }

    /**
     * Configure the plan as the Pro tier.
     */
    public function pro(): static
    {
        return $this->state(fn () => [
            'name' => 'Pro',
            'slug' => 'pro',
            'price_monthly' => 2900,
            'price_yearly' => 24900,
            'limits' => [
                'credits_monthly' => 50000,
                'members' => 5,
                'active_workflows' => 100,
                'max_execution_time_seconds' => 300,
                'execution_log_retention_days' => 30,
                'min_schedule_interval_minutes' => 5,
                'api_rate_limit_per_minute' => 120,
            ],
            'features' => [
                'webhook_triggers' => true,
                'schedule_triggers' => true,
                'import_export' => true,
                'custom_variables' => true,
                'ai_generation' => true,
                'ai_autofix' => true,
                'deterministic_replay' => true,
                'execution_debugger' => true,
                'priority_execution' => false,
                'environments' => false,
                'approval_workflows' => false,
                'connector_metrics' => false,
                'overage_protection' => false,
                'audit_logs' => false,
                'sso_saml' => false,
                'annual_rollover' => true,
                'credit_packs' => true,
            ],
            'sort_order' => 2,
        ]);
    }

    /**
     * Configure the plan as the Teams tier.
     */
    public function teams(): static
    {
        return $this->state(fn () => [
            'name' => 'Teams',
            'slug' => 'teams',
            'price_monthly' => 7900,
            'price_yearly' => 69900,
            'limits' => [
                'credits_monthly' => 200000,
                'members' => 25,
                'active_workflows' => -1,
                'max_execution_time_seconds' => 600,
                'execution_log_retention_days' => 90,
                'min_schedule_interval_minutes' => 1,
                'api_rate_limit_per_minute' => 300,
            ],
            'features' => [
                'webhook_triggers' => true,
                'schedule_triggers' => true,
                'import_export' => true,
                'custom_variables' => true,
                'ai_generation' => true,
                'ai_autofix' => true,
                'deterministic_replay' => true,
                'execution_debugger' => true,
                'priority_execution' => true,
                'environments' => true,
                'approval_workflows' => true,
                'connector_metrics' => true,
                'overage_protection' => false,
                'audit_logs' => false,
                'sso_saml' => false,
                'annual_rollover' => true,
                'credit_packs' => true,
            ],
            'sort_order' => 3,
        ]);
    }

    /**
     * Configure the plan as the Enterprise tier.
     */
    public function enterprise(): static
    {
        return $this->state(fn () => [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'limits' => [
                'credits_monthly' => -1,
                'members' => -1,
                'active_workflows' => -1,
                'max_execution_time_seconds' => -1,
                'execution_log_retention_days' => 365,
                'min_schedule_interval_minutes' => 1,
                'api_rate_limit_per_minute' => -1,
            ],
            'features' => [
                'webhook_triggers' => true,
                'schedule_triggers' => true,
                'import_export' => true,
                'custom_variables' => true,
                'ai_generation' => true,
                'ai_autofix' => true,
                'deterministic_replay' => true,
                'execution_debugger' => true,
                'priority_execution' => true,
                'environments' => true,
                'approval_workflows' => true,
                'connector_metrics' => true,
                'overage_protection' => true,
                'audit_logs' => true,
                'sso_saml' => true,
                'annual_rollover' => true,
                'credit_packs' => true,
            ],
            'sort_order' => 4,
        ]);
    }
}
