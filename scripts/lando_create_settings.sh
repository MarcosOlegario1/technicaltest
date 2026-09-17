#!/bin/sh
# Creates web/sites/default/settings.php on first boot, pointed at Lando's
# own database service. settings.php is intentionally not committed (it is
# environment-specific), so without this the site cannot bootstrap at all
# after a fresh clone: "drush cr" fails with "database connection is not
# defined: default" before a single line of Drupal code runs.
#
# Runs on every `lando start` (see .lando.yml, events.post-start); it is a
# no-op once settings.php already exists, so it never touches a real
# environment's configuration.
set -e

cd "$(dirname "$0")/.."

SETTINGS="web/sites/default/settings.php"

if [ -f "$SETTINGS" ]; then
  exit 0
fi

echo "No $SETTINGS yet — creating one for the Lando database service."

cp web/sites/default/default.settings.php "$SETTINGS"
chmod u+w "$SETTINGS"

# Generate the salt once, here, and bake it in as a literal. Calling
# bin2hex(random_bytes(32)) directly in settings.php would re-run on every
# request, handing Drupal a new hash_salt each time — which invalidates
# sessions and CSRF tokens immediately and makes aggregated CSS/JS filenames
# change on every request, so the browser chases a moving target and loops
# forever on 301s ("too many redirects").
HASH_SALT=$(php -r "echo bin2hex(random_bytes(32));")

cat >> "$SETTINGS" <<PHP

// Added by scripts/lando_create_settings.sh: Lando's own drupal11 recipe
// database, fixed and non-secret (local Docker network only).
\$databases['default']['default'] = [
  'database' => 'drupal11',
  'username' => 'drupal11',
  'password' => 'drupal11',
  'host' => 'database',
  'port' => '3306',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\\\mysql\\\\Driver\\\\Database\\\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];
\$settings['hash_salt'] = '$HASH_SALT';
PHP

echo "Created $SETTINGS."
