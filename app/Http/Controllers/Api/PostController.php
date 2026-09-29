<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PostController extends Controller
{
    public function __construct(private PostService $posts) {}

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return PostResource::collection($this->posts->paginate((int) $request->validated('per_page', 15)));
    }

    public function show(int $id): PostResource
    {
        return new PostResource($this->posts->find($id));
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        return (new PostResource($this->posts->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdatePostRequest $request, int $id): PostResource
    {
        return new PostResource($this->posts->update($id, $request->validated()));
    }

    public function destroy(int $id): Response
    {
        $this->posts->delete($id);

        return response()->noContent();
    }
}
