<?php

declare(strict_types=1);

namespace App\Core\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class AuthService
{
    /**
     * Authenticate user with credentials and issue token.
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{token: string, role: string}|null
     */
    public function login(array $credentials): ?array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'token' => $token,
            'role' => $user->role->value,
        ];
    }

    /**
     * Revoke the current access token of the given user.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
