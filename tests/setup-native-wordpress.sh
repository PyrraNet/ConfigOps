#!/usr/bin/env bash

set -euo pipefail

: "${CONFIGOPS_NATIVE_WP_ROOT:?CONFIGOPS_NATIVE_WP_ROOT is required}"
: "${CONFIGOPS_REPOSITORY_ROOT:?CONFIGOPS_REPOSITORY_ROOT is required}"
: "${CONFIGOPS_PLUGIN_ZIP:=}"
: "${CONFIGOPS_NATIVE_WP_VERSION:=7.0.4}"
: "${CONFIGOPS_DB_HOST:=127.0.0.1:3306}"
: "${CONFIGOPS_DB_NAME:=configops}"
: "${CONFIGOPS_DB_USER:=configops}"
: "${CONFIGOPS_DB_PASSWORD:=configops}"
: "${CONFIGOPS_SITE_URL:=http://127.0.0.1:9402}"

wp_cli_version="2.12.0"
wp_cli_sha256="ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c"
runtime_dir="${RUNNER_TEMP:-/tmp}/configops-native-tools"
wp_cli="${runtime_dir}/wp-cli.phar"

mkdir -p "$runtime_dir" "$CONFIGOPS_NATIVE_WP_ROOT"

if [[ ! -f "$wp_cli" ]]; then
	curl --fail --silent --show-error --location \
		--proto '=https' --tlsv1.2 \
		"https://github.com/wp-cli/wp-cli/releases/download/v${wp_cli_version}/wp-cli-${wp_cli_version}.phar" \
		--output "$wp_cli"
fi

printf '%s  %s\n' "$wp_cli_sha256" "$wp_cli" | sha256sum --check --status

for attempt in {1..60}; do
	if php -r '
		mysqli_report(MYSQLI_REPORT_OFF);
		$database = @new mysqli($argv[1], $argv[2], $argv[3], $argv[4], (int) $argv[5]);
		exit($database->connect_errno ? 1 : 0);
	' "${CONFIGOPS_DB_HOST%:*}" "$CONFIGOPS_DB_USER" "$CONFIGOPS_DB_PASSWORD" "$CONFIGOPS_DB_NAME" "${CONFIGOPS_DB_HOST##*:}"; then
		break
	fi
	if [[ "$attempt" == "60" ]]; then
		echo "Database did not become ready." >&2
		exit 1
	fi
	sleep 1
done

wp() {
	php "$wp_cli" --path="$CONFIGOPS_NATIVE_WP_ROOT" "$@"
}

wp core download --version="$CONFIGOPS_NATIVE_WP_VERSION" --skip-content --quiet
wp config create \
	--dbname="$CONFIGOPS_DB_NAME" \
	--dbuser="$CONFIGOPS_DB_USER" \
	--dbpass="$CONFIGOPS_DB_PASSWORD" \
	--dbhost="$CONFIGOPS_DB_HOST" \
	--dbprefix=wp_ \
	--skip-check \
	--quiet
wp config set WP_ENVIRONMENT_TYPE development --type=constant --quiet
wp core install \
	--url="$CONFIGOPS_SITE_URL" \
	--title="ConfigOps native database test" \
	--admin_user=admin \
	--admin_password=password \
	--admin_email=admin@example.test \
	--skip-email \
	--quiet

if [[ -z "$CONFIGOPS_PLUGIN_ZIP" ]]; then
	archives=("$CONFIGOPS_REPOSITORY_ROOT"/dist/configops-*.zip)
	if [[ ${#archives[@]} -ne 1 || ! -f "${archives[0]}" ]]; then
		echo "Expected exactly one ConfigOps release archive in dist/." >&2
		exit 1
	fi
	CONFIGOPS_PLUGIN_ZIP="${archives[0]}"
fi

if [[ ! -f "$CONFIGOPS_PLUGIN_ZIP" ]]; then
	echo "ConfigOps release archive does not exist: $CONFIGOPS_PLUGIN_ZIP" >&2
	exit 1
fi

unzip -q "$CONFIGOPS_PLUGIN_ZIP" -d "$CONFIGOPS_NATIVE_WP_ROOT/wp-content/plugins"
cp -R \
	"$CONFIGOPS_REPOSITORY_ROOT/tests/fixtures/configops-hostile-fixture" \
	"$CONFIGOPS_NATIVE_WP_ROOT/wp-content/plugins/configops-hostile-fixture"

wp plugin activate configops --quiet
wp plugin install \
	"https://downloads.wordpress.org/plugin/wp-mail-smtp.4.9.0.zip" \
	--activate \
	--quiet
wp plugin install \
	"https://downloads.wordpress.org/plugin/wordpress-seo.28.2.zip" \
	--activate \
	--quiet

wp core version
wp db query 'SELECT VERSION() AS database_version'
wp plugin status configops
wp plugin status wp-mail-smtp
wp plugin status wordpress-seo
