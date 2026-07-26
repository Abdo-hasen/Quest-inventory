<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Enums\UserRole;
use App\Core\Helpers\Shipping\MockShippingProvider;
use App\Core\Helpers\Shipping\ShippingProviderInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ShippingProviderInterface::class,
            MockShippingProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        if (app()->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
                Log::warning('Lazy loading in Production: Model '.get_class($model)." -> Relation: {$relation}");
            });
        } else {
            Model::preventLazyLoading();
        }

        Gate::define('manage-products', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('manage-warehouses', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('manage-users', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('adjust-stock', fn (User $user): bool => $user->role === UserRole::Admin);

        Gate::define('create-orders', fn (User $user): bool => $user->role === UserRole::OrderCreator);
        Gate::define('view-own-orders', fn (User $user): bool => in_array($user->role, [UserRole::OrderCreator, UserRole::Admin], true));

        Gate::define('manage-reservations', fn (User $user): bool => $user->role === UserRole::WarehouseOperator);
        Gate::define('pick-pack-ship', fn (User $user): bool => $user->role === UserRole::WarehouseOperator);
        Gate::define('transfer-stock', fn (User $user): bool => $user->role === UserRole::WarehouseOperator);

        Gate::define('view-inventory', fn (User $user): bool => in_array($user->role, [
            UserRole::Admin,
            UserRole::OrderCreator,
            UserRole::WarehouseOperator,
        ], true));
    }
}
