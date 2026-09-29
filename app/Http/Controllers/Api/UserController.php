<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->paginate((int) $request->validated('per_page', 15)));
    }

    public function show(int $id): UserResource
    {
        return new UserResource($this->users->find($id));
    }
}
