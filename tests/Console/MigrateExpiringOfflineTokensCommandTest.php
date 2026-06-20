<?php

namespace Osiset\ShopifyApp\Test\Console;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Osiset\ShopifyApp\Messaging\Jobs\MigrateShopTokenJob;
use Osiset\ShopifyApp\Test\TestCase;

class MigrateExpiringOfflineTokensCommandTest extends TestCase
{
    public function testDispatchesJobsForLegacyShopsOnly(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_legacy_one',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'password' => 'shpat_legacy_two',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'password' => 'shpat_expiring',
            'shopify_offline_refresh_token' => Crypt::encryptString('shprt_existing'),
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('Dispatched 2 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, 2);
    }

    public function testDryRunDoesNotDispatchJobs(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --dry-run')
            ->expectsOutput('Dry run — 1 shop(s) would be migrated.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testFailsWhenFeatureDisabled(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', false);

        factory($this->model)->create([
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('expiring_offline_tokens is disabled. Set SHOPIFY_EXPIRING_OFFLINE_TOKENS=true first.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function testShopOptionDispatchesSingleJob(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        factory($this->model)->create([
            'name' => 'target.myshopify.com',
            'password' => 'shpat_legacy',
            'shopify_offline_refresh_token' => null,
        ]);
        factory($this->model)->create([
            'name' => 'other.myshopify.com',
            'password' => 'shpat_legacy_other',
            'shopify_offline_refresh_token' => null,
        ]);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens --shop=target.myshopify.com')
            ->expectsOutput('Dispatched 1 migration job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(MigrateShopTokenJob::class, 1);
    }

    public function testReportsWhenNoShopsNeedMigration(): void
    {
        Queue::fake();

        $this->app['config']->set('shopify-app.expiring_offline_tokens', true);

        $this
            ->artisan('shopify-app:migrate-expiring-offline-tokens')
            ->expectsOutput('No shops need migration.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
