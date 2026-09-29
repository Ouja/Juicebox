<?php

namespace App\Services;

use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private UserRepository $users,
        private TokenRepository $tokens,
    ) {}

    /** @param array{name: string, email: string, password: string, password_confirmation?: string} $attributes
     * @return array{user: User, token: string}
     */
    public function register(array $attributes): array
    {
        $email = mb_strtolower($attributes['email']);

        if ($this->users->findByEmail($email) !== null) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }

        try {
            return $this->users->transaction(function () use ($attributes, $email): array {
                $user = $this->users->create([
                    'name' => $attributes['name'],
                    'email' => $email,
                    'password' => Hash::make($attributes['password']),
                ]);
                $token = $this->tokens->create($user);
                SendWelcomeEmail::dispatch($user->id)->afterCommit();

                return ['user' => $user, 'token' => $token];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->users->findByEmail($email) === null) {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    /** @return array{user: User, token: string} */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail(mb_strtolower($email));

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        return ['user' => $user, 'token' => $this->tokens->create($user)];
    }

    public function logout(User $user): void
    {
        $this->tokens->revokeCurrent($user);
    }
}
