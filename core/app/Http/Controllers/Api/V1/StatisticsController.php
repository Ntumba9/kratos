<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Eye\OverviewStatistics;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;

class StatisticsController extends Controller
{
    public function overview(Organisation $organisation, OverviewStatistics $statistics): JsonResponse
    {
        $this->authorize('events.view', $organisation);

        return ApiResponse::ok($statistics->compute());
    }
}
