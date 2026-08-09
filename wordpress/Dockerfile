# Custom-built image: official wordpress:6.9.4-php8.4-apache base with WordPress core
# swapped for a patched point release that Docker Hub hasn't published an official image
# for yet (checked 2026-08-08: WordPress's own stable-check API flags 6.9.4 as "insecure"
# and 6.9.6 as the fixed release on this same major line, but neither 6.9.5 nor 6.9.6 nor
# 7.0.3 have an official Docker image published - only 6.9.4 and 7.0.1/7.0.2 exist, and
# 7.0.2 is *also* still flagged "insecure"). Deliberately stays on the 6.9.x major line
# rather than jumping to 7.x, to avoid combining an untested major-version upgrade
# (new admin UI, theme/plugin compatibility) with this security fix.
#
# Rebuild whenever a newer official image tag for this major line is unavailable but a
# newer WordPress point release exists: bump WP_CORE_VERSION and re-run
# `podman build -t localhost/wordpress:<version>-php8.4-apache -f Dockerfile .`,
# then update docker-compose.yml's image: reference and redeploy. Switch back to an
# official `wordpress:<version>-php8.4-apache` tag once Docker Hub publishes one for the
# version actually in use here - this custom build is a stopgap, not a permanent fixture.
#
# IMPORTANT: /usr/src/wordpress in the official image also contains `wp-config-docker.php`,
# a Docker-specific template (NOT part of the plain wordpress.org release tarball) that the
# entrypoint copies to /var/www/html/wp-config.php on first boot. Deleting the whole
# directory and re-extracting a vanilla tarball wipes this file - the entrypoint then thinks
# WordPress was never configured and redirects every request to the fresh-install wizard
# (setup-config.php), taking the live site down (confirmed live 2026-08-08). Always preserve
# it across the swap, as done below.
ARG WP_CORE_VERSION=6.9.6
FROM docker.io/library/wordpress:6.9.4-php8.4-apache
ARG WP_CORE_VERSION
RUN set -eux; \
	cp /usr/src/wordpress/wp-config-docker.php /tmp/wp-config-docker.php; \
	curl -fSL -o /tmp/wordpress.tar.gz "https://wordpress.org/wordpress-${WP_CORE_VERSION}.tar.gz"; \
	rm -rf /usr/src/wordpress; \
	mkdir -p /usr/src/wordpress; \
	tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C /usr/src/wordpress; \
	rm /tmp/wordpress.tar.gz; \
	cp /tmp/wp-config-docker.php /usr/src/wordpress/wp-config-docker.php; \
	rm /tmp/wp-config-docker.php; \
	chown -R www-data:www-data /usr/src/wordpress
