<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any authorization / gate services.
     */
    public function boot(): void
    {
        Gate::define('manage-products', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('manage-warehouses', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('manage-users', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('adjust-stock', fn (User $user): bool => $user->role === UserRole::Admin);

        Gate::define('create-orders', fn (User $user): bool => $user->role === UserRole::OrderCreator);
        Gate::define('view-own-orders', fn (User $user): bool => $user->role === UserRole::OrderCreator);

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
