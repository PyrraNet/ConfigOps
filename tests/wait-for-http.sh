#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 3 ]]; then
	echo "Usage: $0 <url> <server-log> <command> [arguments...]" >&2
	exit 2
fi

url=$1
server_log=$2
shift 2

attempts=${CONFIGOPS_WAIT_ATTEMPTS:-60}
if [[ ! "$attempts" =~ ^[1-9][0-9]*$ ]] || ((attempts > 600)); then
	echo "CONFIGOPS_WAIT_ATTEMPTS must be an integer between 1 and 600." >&2
	exit 2
fi

for ((attempt = 1; attempt <= attempts; attempt++)); do
	# A WordPress login URL may redirect to the Blueprint landing page. The
	# redirect itself proves the server is ready; following it would load the
	# entire admin screen before the browser test and can exhaust the timeout.
	if curl --fail --silent --max-time 10 "$url" > /dev/null; then
		if "$@"; then
			exit 0
		else
			status=$?
		fi

		if [[ -f "$server_log" ]]; then
			cat "$server_log"
		fi
		exit "$status"
	fi
	sleep 1
done

if [[ -f "$server_log" ]]; then
	cat "$server_log"
fi
echo "Server did not become ready after ${attempts} attempts: ${url}" >&2
exit 1
