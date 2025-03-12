<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        if (env('GOOGLE_CLOUD_KEY_BASE64')) {
            $keyPath = storage_path('app/google-cloud-key.json');
            file_put_contents($keyPath, base64_decode(env('GOOGLE_CLOUD_KEY_BASE64')));
        }
        Schema::defaultStringLength(191);
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
