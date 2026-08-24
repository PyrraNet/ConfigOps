#!/usr/bin/env bash
# ConfigOps pipeline identity for UTF-8 CI logs. Deliberately color-free so
# copied, downloaded, and local logs retain the same wordmark.

set -euo pipefail

workflow_name="${GITHUB_WORKFLOW:-Local quality gate}"
job_name="${GITHUB_JOB:-manual}"

printf '%s\n' \
	'' \
	'  █████ █████ █   █ █████ █████ █████ █████ ████  █████' \
	'  █     █   █ ██  █ █       █   █     █   █ █   █ █    ' \
	'  █     █   █ █ █ █ ████    █   █ ███ █   █ ████  █████' \
	'  █     █   █ █  ██ █       █   █   █ █   █ █         █' \
	'  █████ █████ █   █ █     █████ █████ █████ █     █████' \
	'' \
	'  ───────────  CAPTURE  →  REVIEW  →  RESTORE  ───────────' \
	"  ${workflow_name} · ${job_name}" \
	''
