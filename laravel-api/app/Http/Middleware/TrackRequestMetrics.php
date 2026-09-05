<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smallest-viable request metrics collection, without pulling in a
 * full Prometheus client library.
 *
 * WHY APCu, not an in-memory array: each Apache/PHP request here runs
 * in its own short-lived process/thread, so a plain PHP static/array
 * counter would reset on every single request and never accumulate.
 * APCu is per-host shared memory that survives across requests within
 * the same container, which is exactly enough to make counters and
 * sums meaningful between Prometheus scrapes (default: every 15-30s).
 *
 * WHAT THIS DOES NOT DO: this is not a full Prometheus client (no
 * histogram buckets, no quantiles). It tracks:
 *   - http_requests_total{method,route,status}      (counter)
 *   - http_request_duration_seconds_sum{route}       (counter)
 *   - http_request_duration_seconds_count{route}     (counter)
 * which is enough in Grafana to chart request rate, per-status/error
 * rate, and *average* latency (sum/count) — not latency percentiles.
 * Upgrading to a real client library (e.g. promphp/prometheus_client_php)
 * for histogram buckets is called out as a documented future
 * improvement rather than implemented here, since it wasn't necessary
 * to demonstrate the underlying integration correctly.
 */
class TrackRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationSeconds = microtime(true) - $start;
        $route = $request->route()?->uri() ?? 'unmatched';
        $method = $request->method();
        $status = $response->getStatusCode();

        if (function_exists('apcu_inc')) {
            // apcu_inc() atomically initializes a non-existent key to 0
            // before adding $step, so no separate "does this key exist
            // yet" check is needed.
            $counterKey = "metrics:http_requests_total:{$method}:{$route}:{$status}";
            apcu_inc($counterKey, 1);

            $sumKey = "metrics:http_request_duration_seconds_sum:{$route}";
            $countKey = "metrics:http_request_duration_seconds_count:{$route}";

            // apcu_inc only accepts integer steps, so durations are
            // accumulated as microseconds and converted back to
            // fractional seconds when /api/metrics renders them.
            apcu_inc($sumKey, (int) round($durationSeconds * 1_000_000));
            apcu_inc($countKey, 1);

            // Track every distinct key we create so /api/metrics can
            // enumerate them later without relying on apcu_cache_info()
            // (which is disabled by default outside CLI in some
            // hosting environments).
            $registryKey = 'metrics:registry:requests_total';
            $known = apcu_exists($registryKey) ? apcu_fetch($registryKey) : [];
            if (! in_array($counterKey, $known, true)) {
                $known[] = $counterKey;
                apcu_store($registryKey, $known);
            }

            $routeRegistryKey = 'metrics:registry:routes';
            $knownRoutes = apcu_exists($routeRegistryKey) ? apcu_fetch($routeRegistryKey) : [];
            if (! in_array($route, $knownRoutes, true)) {
                $knownRoutes[] = $route;
                apcu_store($routeRegistryKey, $knownRoutes);
            }
        }

        return $response;
    }
}
