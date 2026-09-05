{{/*
Common labels applied to every resource in this chart.
*/}}
{{- define "research-platform.labels" -}}
app.kubernetes.io/part-of: research-platform
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version | replace "+" "_" }}
{{- end }}

{{/*
Namespace used by all templates — sourced from values so `helm install`
can target a different namespace without editing templates.
*/}}
{{- define "research-platform.namespace" -}}
{{ .Values.namespace }}
{{- end }}
