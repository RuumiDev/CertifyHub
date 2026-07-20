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

        // Ensure default fallback fonts exist locally for GD PDF/Image rendering
        $fontDir = public_path('assets/fonts');
        if (!is_dir($fontDir)) {
            @mkdir($fontDir, 0755, true);
        }
        foreach (['Arial.ttf', 'Inter.ttf'] as $fontFile) {
            $fontPath = $fontDir . '/' . $fontFile;
            // Self-heal corrupt font downloads that contain HTML webpages instead of TrueType binary
            if (file_exists($fontPath)) {
                $fp = @fopen($fontPath, 'r');
                if ($fp) {
                    $header = @fread($fp, 50);
                    @fclose($fp);
                    if ($header && (str_contains($header, '<!DOCTYPE') || str_contains($header, '<html'))) {
                        @unlink($fontPath);
                    }
                }
            }
            if (!file_exists($fontPath)) {
                // Use raw.githubusercontent.com directly to avoid HTTP redirects
                $fontUrl = 'https://raw.githubusercontent.com/google/fonts/main/apache/roboto/static/Roboto-Regular.ttf';
                // Add stream context with user-agent to bypass raw request blocks
                $ctx = stream_context_create([
                    'http' => [
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'follow_location' => true,
                        'timeout' => 15,
                    ]
                ]);
                $fontData = @file_get_contents($fontUrl, false, $ctx);
                if ($fontData && strlen($fontData) > 5000) {
                    @file_put_contents($fontPath, $fontData);
                }
            }
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
