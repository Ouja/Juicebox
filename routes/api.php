<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WeatherController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('posts/{id}', [PostController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('posts.show');
    Route::patch('posts/{id}', [PostController::class, 'update'])->middleware('post.owner')->where('id', '[1-9][0-9]{0,17}')->name('posts.update');
    Route::delete('posts/{id}', [PostController::class, 'destroy'])->middleware('post.owner')->where('id', '[1-9][0-9]{0,17}')->name('posts.destroy');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{id}', [UserController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('users.show');
    Route::get('weather', WeatherController::class)->name('weather');
});
