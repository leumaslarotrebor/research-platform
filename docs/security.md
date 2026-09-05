# Security

## What's actually implemented

- **No secrets committed to Git.** `.env`, `laravel-api/.env`, and any `k8s/*-secret.yaml` (non-`.example`) are gitignored. `.env.example` and `k8s/*.yaml.example` document required variables/keys without real values.
- **Kubernetes Secrets, not ConfigMaps, for credentials.** DB passwords and app keys are injected via `secretKeyRef`, never as plain env values in a Deployment spec or ConfigMap.
- **Helm chart doesn't create secrets with plaintext defaults.** `createSecrets: false` by default — see `docs/architecture.md` for the reasoning.
- **Database credentials separated from application configuration.** `OJS_DB_*` / `DB_*` env vars are distinct from app-level config (`OJS_BASE_URL`, `APP_ENV`, etc.), sourced from different Kubernetes objects (Secret vs. ConfigMap).
- **Non-root file ownership inside containers.** The OJS image explicitly `chown`s the web-writable directories to `www-data` rather than running everything as root.
- **Resource limits everywhere.** Every container (OJS, Laravel API, MySQL) has both `requests` and `limits` set, so one misbehaving pod can't starve the others on a shared node.
- **Dependency/image scanning in CI.** `trivy` scans the built OJS image for known CVEs (report-only — see `docs/deployment.md`/CI comments for why it doesn't hard-fail the build).
- **HTTPS/Ingress considerations documented, not pretended.** The local setup is plain HTTP; `docs/deployment.md`'s local-vs-cloud table explicitly calls out that a real deployment needs TLS termination (cert-manager + Let's Encrypt, or a cloud LB's managed cert) that isn't configured here.

## What is explicitly NOT production-hardened

Be honest about this in an interview — these are real, known gaps, not oversights I'm hiding:

1. **Containers still run as root by default in the OJS image's final layer for package installation**, only the application's own writable directories are `chown`ed to `www-data`. A fully hardened image would run the Apache process itself as a non-root user (this generally requires additional Apache config changes — e.g. binding to a port >1024, or using `setcap` — that add real complexity for a portfolio-scale project).
2. **No NetworkPolicies.** Any pod in the `research-platform` namespace can currently reach MySQL on port 3306. A production setup would restrict this to only the OJS and Laravel API pods.
3. **No image signing/provenance verification** (e.g. cosign/Sigstore) — images are trusted by tag alone.
4. **No secrets rotation strategy.** Secrets are created once, manually; there's no process for rotating DB passwords or app keys without a manual `kubectl` + pod restart.
5. **No rate limiting / WAF** in front of either service.
6. **MySQL runs as a single, non-replicated instance** — see `docs/architecture.md` for why a managed database is the real production recommendation.
7. **Grafana's admin password**, if monitoring is enabled, is generated with `openssl rand` at install time and never persisted anywhere by this project — if you lose it, you reset it via the kube-prometheus-stack chart, not through anything this repo manages.

## Creating secrets (the right way, for this project)

Never write real values into a file that could be `git add`ed by mistake. Prefer generating them directly on the command line, as shown in `docs/deployment.md`:

```bash
kubectl create secret generic mysql-credentials -n research-platform \
  --from-literal=root-password="$(openssl rand -hex 16)" \
  --from-literal=db-name=ojs \
  --from-literal=db-user=ojs_app_user \
  --from-literal=db-password="$(openssl rand -hex 16)"
```

The `k8s/*-secret.yaml.example` files exist purely as documentation of the expected Secret **shape** (name + keys), not as templates you fill in and commit.
