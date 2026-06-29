<?php

namespace App\Http\Controllers\Admin;

use App\Models\ArgoTask;
use Illuminate\Http\JsonResponse;

class ArgoTaskActivityController extends Controller
{
    public function index(ArgoTask $argoTask): JsonResponse
    {
        $activities = $argoTask->activities()
            ->with(['user:id,first_name,last_name,email'])
            ->limit(100)
            ->get();

        return response()->json(['activities' => $activities]);
    }
}
