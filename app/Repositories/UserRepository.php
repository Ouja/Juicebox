<?php

namespace App\Repositories;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserRepository
{
    public function findOrFail(int $id): User
    {
        return User::query()->findOrFail($id);
    }

    public function find(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    /** @param array{name: string, email: string, password: string} $attributes */
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    /** @return LengthAwarePaginator<int, User> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return User::query()->orderBy('id')->paginate($perPage);
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
