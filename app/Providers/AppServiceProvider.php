<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        if ($this->shouldRegisterQueueFallback()) {
            $this->app->terminating(function (): void {
                $this->runSingleQueuedJob();
            });
        }
    }

    private function shouldRegisterQueueFallback(): bool
    {
        if (! $this->app->environment('production')) {
            return false;
        }

        if ($this->app->runningInConsole()) {
            return false;
        }

        if (! filter_var(env('QUEUE_FALLBACK_AFTER_RESPONSE', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        if (config('queue.default') !== 'database') {
            return false;
        }

        try {
            $jobsTable = (string) config('queue.connections.database.table', 'jobs');

            return Schema::hasTable($jobsTable) && DB::table($jobsTable)->exists();
        } catch (\Throwable $exception) {
            Log::warning('CertifyHub: queue fallback readiness check failed.', [
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function runSingleQueuedJob(): void
    {
        try {
            Artisan::call('queue:work', [
                '--once' => true,
                '--queue' => config('queue.connections.database.queue', 'default'),
                '--timeout' => (int) env('QUEUE_FALLBACK_TIMEOUT', 60),
                '--memory' => (int) env('QUEUE_FALLBACK_MEMORY', 128),
            ]);
        } catch (\Throwable $exception) {
            Log::error('CertifyHub: queue fallback run failed.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
