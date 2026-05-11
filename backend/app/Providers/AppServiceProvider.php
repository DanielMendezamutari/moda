<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Report;
use App\Policies\CategoryPolicy;
use App\Policies\ReportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role as SpatieRole;

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
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);

        Route::bind('role', function (string $value): SpatieRole {
            return SpatieRole::query()
                ->where('guard_name', 'api')
                ->whereKey($value)
                ->firstOrFail();
        });
    }
}
