#!/usr/bin/env bash
# Runs the integration scenarios in a real WordPress (wp-env, in docker) against the fake API, on the plug-in as built
# into the ZIP, so the prefixed SDK is what is tested.
#
#   tests/integration/run.sh [php version] [wordpress version, or latest]
#   tests/integration/run.sh 8.2 6.4
#
# Leaves the environment running; stop it with `npx @wordpress/env@11.16.0 stop`, or remove its containers and volumes
# with `... cleanup`.
set -euo pipefail
cd "$(dirname "$0")/../.."

php_version=${1:-8.2}
wp_version=${2:-latest}
plugin_version=$(sed -n 's/^ \* Version: *//p' postilio-for-wordpress.php)
zip="dist/postilio-for-wordpress-$plugin_version.zip"
wp_env=(npx -y @wordpress/env@11.16.0)

if [ ! -f "$zip" ]; then
    ./build.sh --no-checks
fi
if [ -d dist/postilio-for-wordpress ]; then
    rm -r -- dist/postilio-for-wordpress
fi
unzip -q "$zip" -d dist

core=null
if [ "$wp_version" != latest ]; then
    core="\"WordPress/WordPress#$wp_version\""
fi
printf '{ "phpVersion": "%s", "core": %s }\n' "$php_version" "$core" > .wp-env.override.json

"${wp_env[@]}" start --update 2>/dev/null
"${wp_env[@]}" run cli wp eval-file wp-content/postilio-integration/scenarios.php

# The Dutch translation, on a site in Dutch (WordPress's own Dutch language pack installed, as on a real site): a new
# process, so the plug-in loads its text domain on init.
"${wp_env[@]}" run cli wp language core install nl_NL --activate > /dev/null 2>&1
dutch=$("${wp_env[@]}" run cli wp eval "echo __( 'API key', 'postilio-for-wordpress' );" 2>/dev/null | tail -1)
"${wp_env[@]}" run cli wp site switch-language en_US > /dev/null 2>&1
if [ "$dutch" != "API-sleutel" ]; then
    echo "not ok - the Dutch translation is not loaded (got: $dutch)" >&2
    exit 1
fi
echo "ok - the Dutch translation is loaded on a site in Dutch"
