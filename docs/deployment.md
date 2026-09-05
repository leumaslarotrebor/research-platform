# Deployment Guide

## Local development (Docker Compose)

The fastest way to run everything on your machine, without Kubernetes:

```bash
git clone <your-repo-url>
cd research-platform
cp .env.example .env
# edit .env and fill in real values (DB passwords, OJS_APP_KEY)
bash scripts/bootstrap-laravel-api.sh   # generates the real Laravel skeleton (see comment in the script for why this is a separate step)
docker compose up --build
```

- OJS: http://localhost:8080 (first run redirects to OJS's own install wizard)
- Laravel Research API: http://localhost:8081/api/health

## Local Kubernetes (Kind)

```bash
# 1. Install prerequisites (one-time)
#    - Docker
#    - kind: https://kind.sigs.k8s.io/docs/user/quick-start/#installation
#    - kubectl
#    - helm: https://helm.sh/docs/intro/install/

# 2. Create the cluster
kind create cluster --name research-platform --config .github/kind-config.yaml

# 3. Install an ingress controller (Kind doesn't ship one)
kubectl apply -f https://raw.githubusercontent.com/kubernetes/ingress-nginx/controller-v1.11.2/deploy/static/provider/kind/deploy.yaml
kubectl wait --namespace ingress-nginx \
  --for=condition=ready pod \
  --selector=app.kubernetes.io/component=controller \
  --timeout=120s

# 4. Build images and load them directly into Kind (no registry needed for local use)
bash scripts/bootstrap-laravel-api.sh
docker build -t research-platform-ojs:dev ./ojs
docker build -t research-platform-laravel-api:dev ./laravel-api
kind load docker-image research-platform-ojs:dev --name research-platform
kind load docker-image research-platform-laravel-api:dev --name research-platform

# 5. Create secrets (never commit real values — see docs/security.md)
kubectl create namespace research-platform
kubectl create secret generic mysql-credentials -n research-platform \
  --from-literal=root-password="$(openssl rand -hex 16)" \
  --from-literal=db-name=ojs \
  --from-literal=db-user=ojs_app_user \
  --from-literal=db-password="$(openssl rand -hex 16)"
kubectl create secret generic ojs-app-secret -n research-platform \
  --from-literal=app-key="$(openssl rand -base64 32)"
kubectl create secret generic laravel-app-secret -n research-platform \
  --from-literal=app-key="base64:$(openssl rand -base64 32)"

# 6. Install the chart
helm install research-platform helm/research-platform \
  -n research-platform \
  -f helm/research-platform/values-dev.yaml \
  --set ojs.image.repository=research-platform-ojs \
  --set ojs.image.tag=dev \
  --set ojs.image.pullPolicy=Never \
  --set laravelApi.image.repository=research-platform-laravel-api \
  --set laravelApi.image.tag=dev \
  --set laravelApi.image.pullPolicy=Never

# 7. Watch the rollout
kubectl -n research-platform get pods -w

# 8. Access it
echo "127.0.0.1 research-platform.local" | sudo tee -a /etc/hosts
# OJS:          http://research-platform.local/
# Research API: http://research-platform.local/api/health
```

### Upgrading / rolling back

```bash
helm upgrade research-platform helm/research-platform -n research-platform -f helm/research-platform/values-dev.yaml
helm rollback research-platform 1 -n research-platform   # roll back to revision 1
helm history research-platform -n research-platform
```

### Tearing down

```bash
kind delete cluster --name research-platform
```

## Monitoring (optional, off by default)

```bash
helm repo add prometheus-community https://prometheus-community.github.io/helm-charts
helm repo update
kubectl create namespace monitoring
kubectl apply -f monitoring/grafana-dashboard-configmap.yaml
helm install kube-prometheus-stack prometheus-community/kube-prometheus-stack \
  -n monitoring \
  -f monitoring/kube-prometheus-stack-values.yaml \
  --set grafana.adminPassword="$(openssl rand -hex 12)"

# Then enable this project's ServiceMonitor:
helm upgrade research-platform helm/research-platform -n research-platform \
  -f helm/research-platform/values-dev.yaml --reuse-values \
  --set monitoring.enabled=true

# Access Grafana:
kubectl -n monitoring port-forward svc/kube-prometheus-stack-grafana 3000:80
# open http://localhost:3000, log in as "admin" with the password you set above,
# find the "Research Platform" folder → "Research Platform — Laravel API" dashboard.
```

## Local vs. cloud: what actually changes

This project is validated against a **local Kind cluster only** — both by hand and in CI. It is explicitly **not** claiming to run in a real cloud Kubernetes cluster. If you wanted to move this to, say, **AWS EKS**, here's exactly what would need to change:

| Concern | Local (Kind) | Cloud (e.g. EKS) |
|---|---|---|
| Image distribution | `kind load docker-image` — no registry needed | Must push to a real registry (GHCR, ECR) and reference it in `values-prod.yaml` |
| Storage | Kind's built-in `local-path-provisioner`, no `storageClassName` needed | Must set `storageClassName: gp3` (or your cloud's equivalent) — already reflected in `values-prod.yaml` |
| Ingress | `ingress-nginx`'s Kind-specific manifest, hostPort-mapped | A real ingress controller (`ingress-nginx` via LoadBalancer, or the AWS Load Balancer Controller for ALB) + a real DNS record + a TLS certificate (cert-manager + Let's Encrypt, or ACM) |
| Secrets | `kubectl create secret` by hand, or CI's ephemeral `--set` values | A real secrets manager (AWS Secrets Manager / External Secrets Operator) rather than manual `kubectl create secret` |
| Node sizing | Whatever your laptop's Docker Desktop / Kind allows | Real node groups sized for `values-prod.yaml`'s resource requests/limits |
| Database | In-cluster MySQL `Deployment` | Recommended: managed RDS MySQL instead (see `docs/architecture.md` "Should MySQL run inside Kubernetes?") |

I have not provisioned or tested against a real EKS/GKE/AKS cluster for this project — the table above is a documented migration path, not a tested one.

## What the CI/CD Kind deployment actually proves (and doesn't)

The GitHub Actions workflow (`.github/workflows/ci-cd.yml`) creates a real, single-node Kind cluster **inside the CI runner**, deploys the full Helm chart into it, waits for rollouts, and smoke-tests both services through the actual Ingress. This proves:

- the Docker images build correctly;
- the Helm chart's templates render into manifests the Kubernetes API actually accepts;
- OJS, Laravel API, and MySQL can start up, find each other over the cluster network, and pass their health checks in a stock, freshly-created cluster — not just "on my machine";
- the Ingress path-routing rules actually route to the right service.

It does **not** prove:

- behavior under real production load or with multiple nodes;
- behavior on a real cloud provider's networking/storage/ingress stack (see the table above);
- long-running stability — the cluster lives for the duration of one CI job and is then deleted.
