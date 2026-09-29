<?php

namespace App\Repositories;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class TokenRepository
{
    public function create(User $user): string
    {
        return $user->createToken('api', ['*'], now()->addWeek())->plainTextToken;
    }

    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
