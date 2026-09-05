# Architecture

## What is OJS, and what did I build?

**OJS (Open Journal Systems)** is existing, third-party open-source software developed by the [Public Knowledge Project (PKP)](https://pkp.sfu.ca/ojs/). I did not write OJS. What I built is the infrastructure and tooling around it: a reproducible container build, a Kubernetes/Helm deployment, CI/CD, monitoring, and a small companion API.

| Layer | Who built it |
|---|---|
| OJS application code | PKP (third-party, unmodified) |
| `ojs/Dockerfile`, entrypoint, healthcheck | Me |
| MySQL deployment, init scripts | Me |
| `laravel-api/` (Research API) | Me, from scratch |
| Kubernetes manifests, Helm chart | Me |
| CI/CD workflow | Me |
| Monitoring config (kube-prometheus-stack values, dashboard, ServiceMonitor) | Me |

## Version decisions and why

| Component | Version | Why |
|---|---|---|
| OJS | 3.5.0-2 (LTS line) | 3.5 was designated PKP's next Long-Term-Support release; 3.4 was not given LTS status, and 3.3 is being phased out. Building on a lame-duck branch made no sense for a new project. |
| PHP | 8.2 | OJS's own `composer check-platform-reqs` output (from the pkp/ojs GitHub repo) lists PHP 8.2 as the tested baseline. I cross-checked the exact extension list from that output rather than guessing — see the comment block in `ojs/Dockerfile`. |
| MySQL | 8.0 | Actively-patched LTS-style release line, and the version most PKP documentation and community Docker examples assume. |
| Laravel | 12 | Current stable Laravel release, PHP 8.2-compatible. |
| Kubernetes (local) | Kind | Scriptable, disposable, and — importantly — it's what the CI/CD pipeline also uses, so "works locally" and "works in CI" mean the same thing. |

## Why the Laravel API's `articles` table is NOT a live view of OJS's data

This is a deliberate, documented boundary, not an oversight. OJS does not publish its internal database schema as a stable, versioned contract — reaching into `submissions`/`publications`-style tables directly from another service would be:

- **fragile**: those tables can change shape between OJS versions without notice, since they're private implementation detail, not a public API;
- **dishonest to claim as "integration"**: it would look like a real system integration while actually being a schema-scraping hack.

The Research API's `articles`/`researchers` tables are a small, separate, Laravel-owned dataset that demonstrates REST/Eloquent/migrations/validation on their own merits. The realistic way to pull live article data from OJS is through **OJS's own REST API** (`/index.php/<context>/api/v1/submissions`), which is documented in [PKP's API docs](https://docs.pkp.sfu.ca/dev/api/ojs/3.5) — wiring that up is called out as a **documented future improvement** (see "Future improvements" in the main README), not implemented here, so the project doesn't overreach into more OJS-internals knowledge than I can verify and defend.

## Should MySQL run inside Kubernetes?

**For this portfolio project: yes**, and that's what's implemented — a single-replica `Deployment` (not a `StatefulSet`; a single instance doesn't need StatefulSet's ordering/identity guarantees) backed by a `PersistentVolumeClaim`.

**For a real production deployment: no**, I'd use a managed database (AWS RDS, Google Cloud SQL, or Azure Database for MySQL) instead. Reasons:

- Running your own HA MySQL in Kubernetes (replication, failover, backups, patching) is a genuinely hard operational problem that managed services solve for you.
- A managed DB survives cluster deletion/recreation — losing a Kind cluster or even a whole EKS node group shouldn't mean losing your data.
- `values-prod.yaml` still points at the in-cluster MySQL for consistency with the dev/local setup, but the comment there flags this as the first thing I'd change for a real deployment.

## Why OJS runs as a single replica (not horizontally scaled)

OJS's default session handling and uploaded-file storage assume local disk (see the `session.save_path` comment in `ojs/php.ini-production-overrides.ini`). Running more than one OJS replica correctly would require:

- a shared session store (e.g. Redis) so a user's session survives hitting a different replica, and
- a `ReadWriteMany` volume for `/var/www/files` so all replicas see the same uploaded files.

Neither exists in this project — I'm flagging it explicitly rather than silently running `replicas: 1` and hoping nobody asks. The Laravel API, being stateless, *is* scaled to 2-3 replicas since it has no such constraint.

## Why the Laravel API uses APCu-based metrics instead of a Prometheus client library

Prometheus scraping needs counters that persist *across* individual PHP requests. Each Apache/PHP request here is a separate process/thread with no shared memory of its own — a plain in-code counter would reset every request. APCu (a bundled PHP extension providing per-host shared memory) is the smallest mechanism that solves this without adding an unverified third-party Composer dependency. See `app/Http/Middleware/TrackRequestMetrics.php` for the full reasoning, including what this approach deliberately does *not* provide (histogram percentiles) and why average latency (sum/count) was judged sufficient here.

## Why the Helm chart doesn't create secrets by default

`createSecrets: false` is the default. Values files are typically the first thing committed to git in a Helm-based project — if the chart's "happy path" was `helm install` with real passwords in `values.yaml`, that would be an actively bad security default. Instead, secrets are expected to already exist in the target namespace (via `kubectl create secret generic ...`, documented in `docs/security.md`), and `createSecrets: true` + `--set secretValues.xxx=...` is the explicit opt-in path CI uses for its own throwaway Kind cluster.

## What I'd add given more time

- Redis-backed sessions + `ReadWriteMany` storage to actually scale OJS beyond 1 replica.
- Real integration with OJS's REST API from the Laravel service, instead of a self-contained dataset.
- A proper Prometheus client library (histogram buckets, not sum/count) once APCu's per-request-latency limitations start to matter.
- Network policies restricting which pods can reach MySQL (currently: anything in-namespace can).
