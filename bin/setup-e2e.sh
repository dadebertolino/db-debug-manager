#!/usr/bin/env bash
#
# Prepara l'ambiente wp-env per gli E2E di DB Debug Manager. Fallisce
# (set -e) sui passi essenziali.
#
# Oltre al solito (permalink, plugin attivo, baseline) serve:
#  - pdo_mysql nel container wordpress: emergency.php si collega al database
#    con PDO, senza WordPress; l'immagine ufficiale installa solo mysqli;
#  - permessi di scrittura per il server web su wp-config.php (il plugin lo
#    modifica) e sulla cartella del plugin (oggi vi crea la cartella
#    privata): in CI i file appartengono all'utente del runner.
#
set -euo pipefail

run() { npx wp-env run cli wp "$@"; }

# Container "wordpress" dell'ambiente di sviluppo (non quello dei test).
WP_CONTAINER="$(docker ps --format '{{.Names}}' | grep -E -- '-wordpress-1$' | grep -v -- '-tests-' | head -1)"
if [ -z "${WP_CONTAINER}" ]; then
	echo "::error::Container wordpress di wp-env non trovato." >&2
	docker ps
	exit 1
fi
as_root() { docker exec -u root "${WP_CONTAINER}" sh -c "$1"; }
echo "→ Container: ${WP_CONTAINER}"

echo "→ pdo_mysql per emergency.php"
if as_root 'php -m' | grep -qi '^pdo_mysql$'; then
	echo "  già presente"
else
	as_root 'docker-php-ext-install pdo_mysql >/tmp/pdo-install.log 2>&1 || { cat /tmp/pdo-install.log; exit 1; }'
	as_root 'apache2ctl -k graceful'
	sleep 2
	as_root 'php -m' | grep -qi '^pdo_mysql$' || { echo "::error::pdo_mysql non installato." >&2; exit 1; }
	echo "  installato"
fi

echo "→ Permessi di scrittura per il server web"
as_root 'chmod 666 /var/www/html/wp-config.php'
as_root 'chmod a+rwx /var/www/html/wp-content /var/www/html/wp-content/plugins/db-debug-manager'

echo "→ Permalink pretty"
run rewrite structure '/%postname%/' --hard
run rewrite flush --hard

echo "→ DB Debug Manager attivo"
run plugin activate db-debug-manager || true
if ! run plugin is-active db-debug-manager 2>/dev/null; then
	echo "::error::DB Debug Manager non attivo. Setup fallito." >&2
	run plugin list
	exit 1
fi

echo "→ Stato baseline (copia dorata di wp-config.php)"
curl -fsS -X POST -H 'Content-Type: application/json' -d '{}' \
	'http://localhost:8888/?rest_route=/dbdm-e2e/v1/reset' >/dev/null

echo "Setup E2E completato."
