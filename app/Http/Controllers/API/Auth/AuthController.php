<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Auth;

use App\Core\Services\Auth\AuthService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if (is_null($result)) {
            return $this->sendFailedResponse(
                message: __('Invalid credentials'),
                code: 401
            );
        }

        return $this->sendSuccessResponse(
            data: $result,
            message: __('Login successful')
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->sendSuccessResponse(
            data: [],
            message: __('Logged out successfully')
        );
    }
}
