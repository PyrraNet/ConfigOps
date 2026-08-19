#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="$repository_root/tests/coverage/compose.yaml"
coverage_dir="$repository_root/coverage"
project_name="configops-coverage"

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
	printf 'Docker with a running daemon is required for reproducible Xdebug coverage.\n' >&2
	exit 2
fi

if [[ "$coverage_dir" != "$repository_root/coverage" ]]; then
	printf 'Refusing to clean an unexpected coverage directory.\n' >&2
	exit 2
fi

cleanup() {
	if [[ "${CONFIGOPS_COVERAGE_KEEP:-0}" != "1" ]]; then
		docker compose --project-name "$project_name" --file "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

rm -rf "$coverage_dir"
install -d -m 0777 "$coverage_dir/raw"
git -C "$repository_root" ls-files -- src > "$coverage_dir/source-files.txt"
if [[ ! -s "$coverage_dir/source-files.txt" ]]; then
	printf 'No tracked production sources were found for coverage.\n' >&2
	exit 2
fi
chmod 0666 "$coverage_dir/source-files.txt"

docker compose --project-name "$project_name" --file "$compose_file" up --detach --build

compose() {
	docker compose --project-name "$project_name" --file "$compose_file" "$@"
}

printf 'Waiting for the isolated coverage site'
for attempt in {1..90}; do
	if compose exec --no-TTY --user www-data wordpress wp core version --path=/var/www/html >/dev/null 2>&1; then
		printf '\n'
		break
	fi
	if [[ "$attempt" == "90" ]]; then
		printf '\nCoverage WordPress did not become ready.\n' >&2
		compose logs --tail=120 wordpress database >&2
		exit 1
	fi
	printf '.'
	sleep 2
done

wp() {
	compose exec --no-TTY --user www-data wordpress wp --path=/var/www/html "$@"
}

if ! wp core is-installed >/dev/null 2>&1; then
	wp core install \
		--url=http://configops-coverage.test \
		--title='ConfigOps coverage' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email \
		--quiet
fi
collect() {
	local test_name="$1"
	local output_name="$2"
	compose exec --no-TTY \
		--env CONFIGOPS_WP_ROOT=/var/www/html \
		--env CONFIGOPS_COVERAGE_SOURCE_ROOT=/var/www/html/wp-content/plugins/configops \
		--env CONFIGOPS_COVERAGE_SOURCE_MANIFEST=/var/www/html/wp-content/plugins/configops/coverage/source-files.txt \
		wordpress php \
		/var/www/html/wp-content/plugins/configops/tests/coverage/collect.php \
		"/var/www/html/wp-content/plugins/configops/$test_name" \
		"/var/www/html/wp-content/plugins/configops/coverage/raw/$output_name.json"
}

collect tests/run.php unit
collect tests/adversarial.php adversarial
collect tests/integration.php integration

wp plugin install 'https://downloads.wordpress.org/plugin/wp-mail-smtp.4.9.0.zip' --activate --quiet
wp plugin install 'https://downloads.wordpress.org/plugin/wordpress-seo.28.2.zip' --activate --quiet
collect tests/adapters-integration.php adapters

wp plugin deactivate configops --quiet
wp core multisite-convert --title='ConfigOps coverage network'
wp plugin activate configops --network --quiet
collect tests/multisite-integration.php multisite

cd "$repository_root"
node tests/coverage/report.mjs --minimum=70 --critical-minimum=75
