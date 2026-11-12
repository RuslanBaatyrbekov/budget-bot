<?php

namespace App\Providers;

use App\Services\Ai\GeminiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class, fn () => new GeminiClient(
            apiKey: config('services.gemini.key'),
            model: config('services.gemini.model'),
            timeout: config('services.gemini.timeout'),
        ));
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
    }
}
