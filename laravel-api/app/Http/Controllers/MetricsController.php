<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Hand-rolled Prometheus text-exposition-format endpoint. See
 * app/Http/Middleware/TrackRequestMetrics.php for where these numbers
 * come from and why APCu is used instead of an in-memory counter.
 *
 * Format reference: https://prometheus.io/docs/instrumenting/exposition_formats/
 */
class MetricsController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [];

        $lines[] = '# HELP http_requests_total Total number of HTTP requests handled, by method/route/status.';
        $lines[] = '# TYPE http_requests_total counter';

        $requestCounterKeys = function_exists('apcu_fetch')
            ? (apcu_exists('metrics:registry:requests_total') ? apcu_fetch('metrics:registry:requests_total') : [])
            : [];

        foreach ($requestCounterKeys as $key) {
            // key shape: metrics:http_requests_total:{method}:{route}:{status}
            $parts = explode(':', $key);
            [$method, $route, $status] = [$parts[2], $parts[3], $parts[4]];
            $value = apcu_fetch($key) ?: 0;
            $lines[] = sprintf(
                'http_requests_total{method="%s",route="%s",status="%s"} %d',
                $method,
                $route,
                $status,
                $value
            );
        }

        $lines[] = '# HELP http_request_duration_seconds_sum Cumulative request duration in seconds, by route.';
        $lines[] = '# TYPE http_request_duration_seconds_sum counter';
        $lines[] = '# HELP http_request_duration_seconds_count Number of requests measured, by route.';
        $lines[] = '# TYPE http_request_duration_seconds_count counter';

        $routes = function_exists('apcu_fetch')
            ? (apcu_exists('metrics:registry:routes') ? apcu_fetch('metrics:registry:routes') : [])
            : [];

        foreach ($routes as $route) {
            $sumMicros = apcu_exists("metrics:http_request_duration_seconds_sum:{$route}")
                ? apcu_fetch("metrics:http_request_duration_seconds_sum:{$route}")
                : 0;
            $count = apcu_exists("metrics:http_request_duration_seconds_count:{$route}")
                ? apcu_fetch("metrics:http_request_duration_seconds_count:{$route}")
                : 0;

            $sumSeconds = $sumMicros / 1_000_000;

            $lines[] = sprintf('http_request_duration_seconds_sum{route="%s"} %f', $route, $sumSeconds);
            $lines[] = sprintf('http_request_duration_seconds_count{route="%s"} %d', $route, $count);
        }

        // Deliberately NOT emitting a hand-rolled "up" gauge here:
        // Prometheus itself already synthesizes up{job=...}=1|0 for
        // every scrape target automatically (see
        // monitoring/servicemonitor.yaml and docs/architecture.md) —
        // duplicating that as an app-level metric would be redundant
        // and could disagree with what Prometheus itself observed.

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; version=0.0.4');
    }
}
