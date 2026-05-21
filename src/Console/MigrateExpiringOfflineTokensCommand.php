<?php

namespace Osiset\ShopifyApp\Console;

use Illuminate\Console\Command;
use Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken;
use Osiset\ShopifyApp\Util;

class MigrateExpiringOfflineTokensCommand extends Command
{
    protected $signature = 'shopify-app:migrate-expiring-offline-tokens
        {--shop= : Migrate a single shop by domain (e.g. example.myshopify.com)}
        {--dry-run : List shops that would be migrated without calling Shopify}';

    protected $description = 'Migrate legacy non-expiring offline tokens to expiring offline tokens (optional; requires SHOPIFY_EXPIRING_OFFLINE_TOKENS)';

    public function handle(MigrateShopToExpiringOfflineAccessToken $migrate): int
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens')) {
            $this->error('expiring_offline_tokens is disabled. Set SHOPIFY_EXPIRING_OFFLINE_TOKENS=true first.');

            return self::FAILURE;
        }

        $modelClass = Util::getShopifyConfig('user_model');
        $shopDomain = $this->option('shop');

        $query = $modelClass::query()
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->where(function ($q) {
                $q->whereNull('shopify_offline_refresh_token')
                    ->orWhere('shopify_offline_refresh_token', '');
            });

        if ($shopDomain) {
            $query->where('name', $shopDomain);
        }

        $shops = $query->get();

        if ($shops->isEmpty()) {
            $this->info('No shops need migration.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — shops that would be migrated:');
            foreach ($shops as $shop) {
                $this->line('  - '.$shop->name.' (id: '.$shop->id.')');
            }

            return self::SUCCESS;
        }

        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            $result = $migrate($shop);

            if ($result['migrated']) {
                $migrated++;
                $this->info("Migrated: {$shop->name}");

                continue;
            }

            if ($result['skipped']) {
                $skipped++;
                $this->line("Skipped: {$shop->name} ({$result['reason']})");

                continue;
            }

            $failed++;
            $this->error("Failed: {$shop->name} — {$result['error']}");
        }

        $this->newLine();
        $this->info("Done. Migrated: {$migrated}, skipped: {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
