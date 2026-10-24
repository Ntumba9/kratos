<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * The role catalogue with each role's permissions.
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('id')->get();

        return ApiResponse::ok(RoleResource::collection($roles));
    }
}
