<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserService
{
    public function __construct(private UserRepository $users) {}

    public function find(int $id): User
    {
        return $this->users->findOrFail($id);
    }

    /** @return LengthAwarePaginator<int, User> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->users->paginate($perPage);
    }
}
