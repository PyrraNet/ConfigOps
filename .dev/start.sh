#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repository_root"

docker compose up --detach --build

printf 'Waiting for WordPress'
for attempt in {1..60}; do
	if docker compose exec --no-TTY --user www-data wordpress \
		wp core version --path=/var/www/html >/dev/null 2>&1; then
		printf '\n'
		break
	fi

	if [[ "$attempt" == "60" ]]; then
		printf '\nWordPress did not become ready. Recent container output:\n' >&2
		docker compose logs --tail=100 wordpress >&2
		exit 1
	fi

	printf '.'
	sleep 2
done

wp() {
	docker compose exec --no-TTY --user www-data wordpress \
		wp --path=/var/www/html "$@"
}

activate_plugin() {
	if ! wp plugin is-active "$1" >/dev/null 2>&1; then
		wp plugin activate "$1"
	fi
}

wordpress_port="$(docker compose port wordpress 80)"
wordpress_port="${wordpress_port##*:}"
site_url="http://localhost:${wordpress_port}"
mailpit_port="$(docker compose port mailpit 8025)"
mailpit_port="${mailpit_port##*:}"

if ! wp core is-installed >/dev/null 2>&1; then
	wp core install \
		--url="$site_url" \
		--title="ConfigOps Development" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@configops.test \
		--skip-email
fi

current_url="$(wp option get home)"
if [[ "$current_url" != "$site_url" ]]; then
	wp option update home "$site_url"
	wp option update siteurl "$site_url"
fi

if [[ "$(wp option get permalink_structure)" != '/%postname%/' ]]; then
	wp rewrite structure '/%postname%/' --hard
fi

activate_plugin configops

if ! wp plugin is-installed wp-mail-smtp; then
	wp plugin install "https://downloads.wordpress.org/plugin/wp-mail-smtp.4.9.0.zip"
fi
activate_plugin wp-mail-smtp

if ! wp plugin is-installed wordpress-seo; then
	wp plugin install "https://downloads.wordpress.org/plugin/wordpress-seo.28.2.zip"
fi
activate_plugin wordpress-seo

if ! wp plugin is-installed query-monitor; then
	if ! wp plugin install query-monitor --activate; then
		printf 'Warning: Query Monitor could not be installed; continuing without it.\n' >&2
	fi
else
	activate_plugin query-monitor
fi

printf '\nConfigOps development is ready.\n'
printf 'Site:      %s\n' "$site_url"
printf 'Admin:     %s/wp-admin/ (admin / password)\n' "$site_url"
printf 'ConfigOps: %s/wp-admin/admin.php?page=configops\n' "$site_url"
printf 'Mailpit:   http://localhost:%s\n' "$mailpit_port"
