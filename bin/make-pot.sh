#!/usr/bin/env bash
#
# Rigenera languages/db-debug-manager.pot dalle stringhe del plugin
# (funzioni di traduzione di WordPress). emergency.php e inc/emergency/ sono
# esclusi: girano senza WordPress e restano in italiano.
#
set -euo pipefail
cd "$(dirname "$0")/.."

find . -name '*.php' \
	-not -path './vendor/*' -not -path './node_modules/*' -not -path './tests/*' \
	-not -path './inc/emergency/*' -not -name 'emergency.php' -not -name 'uninstall.php' \
	| sort \
	| xargs xgettext --language=PHP --from-code=UTF-8 --add-comments=translators \
		--keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
		--keyword=esc_attr__ --keyword=esc_attr_e --keyword=_x:1,2c --keyword=_n:1,2 \
		--package-name='DB Debug Manager' --msgid-bugs-address='https://github.com/dadebertolino/db-debug-manager/issues' \
		--sort-by-file -o languages/db-debug-manager.pot

sed -i.bak 's/^"Content-Type: text\/plain; charset=CHARSET\\n"/"Content-Type: text\/plain; charset=UTF-8\\n"/' languages/db-debug-manager.pot
rm -f languages/db-debug-manager.pot.bak
echo "languages/db-debug-manager.pot: $(grep -c '^msgid ' languages/db-debug-manager.pot) stringhe"
