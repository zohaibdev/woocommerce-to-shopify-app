<?php

namespace App\Providers;

use App\Services\MailSettings;
use Illuminate\Support\ServiceProvider;

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
        // SMTP is configured from the admin panel, not .env (shared hosting gives
        // the super admin no way to edit .env). No-ops when nothing is stored.
        MailSettings::apply();
    }
}
