# Troubleshooting

General diagnostic commands, then specific failure modes.

## General diagnostics

```bash
kubectl get pods -n research-platform
kubectl describe pod <pod-name> -n research-platform
kubectl logs <pod-name> -n research-platform --all-containers --tail=200
kubectl exec -it <pod-name> -n research-platform -- bash
kubectl get svc -n research-platform
kubectl get ingress -n research-platform
kubectl get pvc -n research-platform
kubectl get events -n research-platform --sort-by=.lastTimestamp
```

## `CrashLoopBackOff`

**Diagnose:**
```bash
kubectl logs <pod-name> -n research-platform --previous
```
The `--previous` flag is important — it shows logs from the *last* crashed attempt, not the current (also-crashing) one.

**Common causes in this project:**
- OJS: `config.inc.php` generation failed because a required env var (`OJS_DB_HOST`, `OJS_DB_NAME`, `OJS_DB_USER`, `OJS_DB_PASSWORD`) wasn't set — `docker-entrypoint.sh` explicitly `exit 1`s with a clear message via `: "${VAR:?message}"`, so check the log for that.
- Laravel API: migrations failed against an unreachable or not-yet-ready MySQL — the entrypoint retries for 60 seconds before giving up; if MySQL took longer than that to become ready, this can crash-loop transiently. `kubectl get pods -n research-platform -w` and see if it self-resolves after MySQL's own probes go green.

## `ImagePullBackOff`

**Diagnose:**
```bash
kubectl describe pod <pod-name> -n research-platform | grep -A5 Events
```

**Common causes:**
- Running against a local Kind cluster but forgot `--set ojs.image.pullPolicy=Never` / `laravelApi.image.pullPolicy=Never` — without this, Kubernetes tries to pull from a registry instead of using the image you `kind load`ed.
- Forgot to run `kind load docker-image ...` after rebuilding an image — Kind's node has no network access to see images that only exist in your host Docker daemon.
- On a real registry (GHCR): image name/tag typo, or the image is private and no `imagePullSecrets` is configured.

## Database connection failure

**Diagnose:**
```bash
kubectl exec -it deploy/laravel-api -n research-platform -- php artisan tinker --execute="DB::connection()->getPdo();"
kubectl logs deploy/mysql -n research-platform
```

**Common causes:**
- `mysql-credentials` Secret keys don't match what the Deployment expects (`root-password`, `db-name`, `db-user`, `db-password` — exact key names, see `k8s/mysql-secret.yaml.example`).
- MySQL pod is still starting (first boot on a fresh PVC can take 10-20+ seconds) — check `kubectl get pods -n research-platform` for `mysql`'s readiness.
- Applied the Secret to the wrong namespace.

## Permission errors (OJS)

**Diagnose:**
```bash
kubectl exec -it deploy/ojs -n research-platform -- ls -la /var/www/files /var/www/html/public
```

**Common cause:** the `ojs-files`/`ojs-public` PVCs were provisioned with a different UID's data already on them (e.g. from an older container image build) than the current image's `www-data` UID expects. The Dockerfile's `chown -R www-data:www-data` only runs once, at build time, on the *image's* filesystem — it does not retroactively fix ownership on a pre-existing PVC's contents. Fix: `kubectl exec -it deploy/ojs -n research-platform -- chown -R www-data:www-data /var/www/files /var/www/html/public`.

## PVC stuck `Pending`

**Diagnose:**
```bash
kubectl describe pvc <pvc-name> -n research-platform
```

**Common causes:**
- No default `StorageClass` in the cluster, and `storageClassName` wasn't explicitly set. Kind ships a default `standard` StorageClass out of the box, so this usually only bites on a cloud cluster (see `docs/deployment.md`'s local-vs-cloud table) — set `storageClassName` in your values file to match your cloud provider.
- Requested storage size exceeds what the underlying provisioner/node can offer.

## OJS configuration issues

**Diagnose:**
```bash
kubectl exec -it deploy/ojs -n research-platform -- cat /var/www/html/config.inc.php
```

**Common cause:** `docker-entrypoint.sh` only generates `config.inc.php` if it doesn't already exist, so it won't pick up *changed* env vars on a pod restart against an existing PVC — you'd need to `kubectl exec` in and manually edit it, or delete the PVC and let it regenerate (losing OJS's own install-wizard progress in the process).

## Ingress not working

**Diagnose:**
```bash
kubectl get ingress -n research-platform
kubectl describe ingress research-platform -n research-platform
kubectl get pods -n ingress-nginx
```

**Common causes:**
- No ingress controller installed at all — this project's Ingress manifest only creates *routing rules*; it assumes `ingress-nginx` (or another controller matching `ingressClassName: nginx`) is already running in the cluster (see `docs/deployment.md` step 3).
- Testing without a `Host` header / `/etc/hosts` entry for `research-platform.local` — path-based routing here is also host-based, so `curl http://localhost/` without the right `Host` header won't match the rule.

## Laravel database migration failures

**Diagnose:**
```bash
kubectl logs deploy/laravel-api -n research-platform | grep -A20 -i migrat
```

**Common causes:**
- The `research_api` database doesn't exist yet — check that `mysql-init-scripts` ConfigMap actually ran (init scripts only run on a MySQL container's *first* boot against an empty data directory; if the PVC already had data from a previous run without this init script, it won't re-run). Fix: connect to MySQL and run the `CREATE DATABASE` statement manually, or wipe the PVC on a fresh setup.
- A migration file has a bug — since this is a small, hand-written schema (see `database/migrations/`), check the actual PHP for typos before assuming an infrastructure problem.
