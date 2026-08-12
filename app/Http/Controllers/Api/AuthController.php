<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthController extends Controller
{
    /**
     * Return the currently authenticated user. Authentication is handled by
     * Supabase; the API trusts the validated bearer access token and returns
     * the corresponding local user record.
     */
    public function user(Request $request): JsonResource
    {
        return new UserResource($request->user());
    }
}
