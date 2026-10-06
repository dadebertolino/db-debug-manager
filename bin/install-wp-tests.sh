#!/usr/bin/env bash
#
# Installa la WordPress test suite + un database di test per i test di
# integrazione (WP_UnitTestCase). È lo script standard usato dai plugin
# WordPress (versione adattata di install-wp-tests.sh dallo scaffold WP-CLI).
#
# Uso: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# wp-version: latest (default), una versione (es. 6.6) oppure trunk/nightly
# (build notturna di WordPress + test suite di trunk, per la run notturna).
#
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"

download() {
	if command -v curl >/dev/null; then
		# -f: un 404 è un errore, non una pagina HTML salvata come file.
		curl -fsSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

# Risolve la versione di WP e il ramo della test suite.
if [ "${WP_VERSION}" = "latest" ]; then
	VERSION_INFO="$(download https://api.wordpress.org/core/version-check/1.7/ - 2>/dev/null || true)"
	WP_VERSION="$(echo "${VERSION_INFO}" | grep -o '"version":"[^"]*"' | head -1 | sed 's/.*:"\(.*\)"/\1/')"
	WP_VERSION="${WP_VERSION:-6.5}"
fi
if [ "${WP_VERSION}" = "trunk" ] || [ "${WP_VERSION}" = "nightly" ]; then
	WP_VERSION="trunk"
	WP_TESTS_TAG="trunk"
else
	WP_TESTS_TAG="tags/${WP_VERSION}"
fi

install_wp() {
	mkdir -p "${WP_CORE_DIR}"
	if [ "${WP_VERSION}" = "trunk" ]; then
		local zip="/tmp/wordpress-nightly.zip"
		download https://wordpress.org/nightly-builds/wordpress-latest.zip "${zip}"
		rm -rf /tmp/wordpress-nightly
		unzip -q "${zip}" -d /tmp/wordpress-nightly
		cp -R /tmp/wordpress-nightly/wordpress/. "${WP_CORE_DIR}"
	else
		local archive="/tmp/wordpress.tar.gz"
		download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "${archive}"
		tar --strip-components=1 -zxmf "${archive}" -C "${WP_CORE_DIR}"
	fi
	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "${WP_CORE_DIR}/wp-content/db.php" || true
}

install_test_suite() {
	mkdir -p "${WP_TESTS_DIR}"
	rm -rf "${WP_TESTS_DIR}/includes" "${WP_TESTS_DIR}/data"

	svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes" \
		|| svn co --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes"
	svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "${WP_TESTS_DIR}/data" \
		|| svn co --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/data/" "${WP_TESTS_DIR}/data"

	# wp-tests-config.php
	local cfg="${WP_TESTS_DIR}/wp-tests-config.php"
	download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "${cfg}" \
		|| download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "${cfg}"

	# Percorso ABSPATH col trailing slash.
	local wp_core_dir_esc
	wp_core_dir_esc="$(echo "${WP_CORE_DIR}" | sed "s:/\+$:/:")"
	sed -i "s:dirname( __FILE__ ) . '/src/':'${wp_core_dir_esc}':" "${cfg}"
	sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "${cfg}"
	sed -i "s/yourusernamehere/${DB_USER}/" "${cfg}"
	sed -i "s/yourpasswordhere/${DB_PASS}/" "${cfg}"
	sed -i "s|localhost|${DB_HOST}|" "${cfg}"
}

create_db() {
	# Il DB è creato dal servizio MySQL del workflow; qui garantiamo solo che esista.
	mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST}" --protocol=tcp 2>/dev/null || true
}

install_wp
install_test_suite
create_db

echo "WordPress test suite installata in ${WP_TESTS_DIR}"
