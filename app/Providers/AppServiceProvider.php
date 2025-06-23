<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Observers\InvoiceObserver;
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
        Schema::defaultStringLength(191);
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        Invoice::observe(InvoiceObserver::class);
    }
}
