FROM wordpress:7.0.3-php8.3-apache

ARG XDEBUG_VERSION=3.4.5
ARG WP_CLI_VERSION=2.12.0
ARG WP_CLI_SHA256=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends $PHPIZE_DEPS default-mysql-client less msmtp; \
	pecl install "xdebug-${XDEBUG_VERSION}"; \
	docker-php-ext-enable xdebug; \
	curl --fail --location --proto '=https' --tlsv1.2 \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
		--output /usr/local/bin/wp; \
	echo "${WP_CLI_SHA256}  /usr/local/bin/wp" | sha256sum --check --status; \
	chmod +x /usr/local/bin/wp; \
	apt-get purge -y --auto-remove $PHPIZE_DEPS; \
	rm -rf /tmp/pear /var/lib/apt/lists/*

COPY .dev/php-dev.ini /usr/local/etc/php/conf.d/configops-dev.ini
COPY .dev/msmtprc /etc/msmtprc

RUN chmod 0644 /etc/msmtprc; \
	mkdir -p /var/www/.wp-cli/cache; \
	chown -R www-data:www-data /var/www/.wp-cli
