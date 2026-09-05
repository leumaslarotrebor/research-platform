<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ResearcherController;
use Illuminate\Support\Facades\Route;

// Research API — a small companion service to OJS, not a replacement
// for it. See docs/architecture.md for the boundary between this
// service and the OJS platform.

Route::get('/health', HealthController::class);

// Scraped by Prometheus via the ServiceMonitor in
// helm/research-platform/templates/servicemonitor.yaml. See
// app/Http/Middleware/TrackRequestMetrics.php for where the numbers
// come from.
Route::get('/metrics', MetricsController::class);

Route::get('/researchers', [ResearcherController::class, 'index']);
Route::post('/researchers', [ResearcherController::class, 'store']);

Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{article}', [ArticleController::class, 'show']);
