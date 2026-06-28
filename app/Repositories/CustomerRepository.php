<?php

namespace App\Repositories;

use App\Models\Commercial;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CustomerRepository
{
    private const int CACHE_TTL_SECONDS = 36000;

    private const string CACHE_VERSION_KEY = 'customers.cache.version';

    private function getCurrentCacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 0);
    }

    /**
     * Return all customers visible to the given commercial, served from cache.
     *
     * The cache key embeds the global version counter so that incrementing
     * the counter (via invalidateAllCaches) effectively orphans every
     * previously cached key without needing to know each one.
     */
    public function getAllForCommercial(Commercial $commercial): Collection
    {
        $version = $this->getCurrentCacheVersion();
        $cacheKey = "customers.commercial.{$commercial->id}.v{$version}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($commercial): Collection {
            return Customer::query()
                ->when(
                    $commercial->team_id !== null,
                    fn (Builder $query) => $query->whereHas(
                        'commercial',
                        fn (Builder $subQuery) => $subQuery->where('team_id', $commercial->team_id)
                    ),
                    fn (Builder $query) => $query->where('commercial_id', $commercial->id)
                )
                ->latest()
                ->get();
        });
    }

    /**
     * Bump the global version counter, which orphans every cached customer
     * collection without needing a list of individual cache keys.
     */
    public function invalidateAllCaches(): void
    {
        Cache::increment(self::CACHE_VERSION_KEY);
    }
}
