<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Used by Docker HEALTHCHECK and Kubernetes liveness/readiness
     * probes (see k8s/laravel-deployment.yaml). Checks that the app
     * can actually reach its database, not just that PHP is running.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'database' => 'unreachable',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'database' => $dbStatus,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
