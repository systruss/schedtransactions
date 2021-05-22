<?php

namespace Systruss\SchedTransactions\Providers;

use Illuminate\Support\ServiceProvider;
// use Systruss\SchedTransactions\Commands\ScheduleJob;

class SchedTransactionsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ScheduleJob::class,
            ]);
        }
    }
}