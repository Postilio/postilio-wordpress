#!/usr/bin/env bash
# Builds dist/postilio-for-wordpress-<version>.zip from the last commit: checks the code (composer, code style, static
# analysis, unit tests), prefixes the SDK's namespaces, and packs only what WordPress runs. The same commit gives the same
# ZIP, byte for byte. It refuses to pack a development file, an unprefixed dependency, or anything that looks like an API
# key or a webhook secret.
#
#   ./build.sh               build from HEAD
#   ./build.sh --no-checks   skip the code checks (the ZIP checks still run)
set -euo pipefail
cd "$(dirname "$0")"

checks=1
if [ "${1:-}" = "--no-checks" ]; then
    checks=0
fi

version=$(sed -n 's/^ \* Version: *//p' postilio-for-wordpress.php)
if ! grep -q "public const VERSION = '$version';" src/Plugin.php; then
    echo "The version in the plug-in header ($version) and Plugin::VERSION differ." >&2
    exit 1
fi
if ! grep -q "^Stable tag: $version$" readme.txt; then
    echo "The Stable tag in readme.txt is not $version." >&2
    exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
    echo "Note: uncommitted changes are not in the build; it is made from HEAD ($(git rev-parse --short HEAD))." >&2
fi

work=$(mktemp -d -t postilio-wp-build.XXXXXX)
trap 'rm -r -- "$work"' EXIT
source="$work/source"
stage="$work/postilio-for-wordpress"
mkdir "$source" "$stage"

# The committed tree, so nothing from the working copy slips in.
git archive --format=tar HEAD | tar -x -C "$source"
(
    cd "$source"
    # Not --strict: the SDK is pinned to a commit until it has a release tag, which composer warns about.
    composer validate --no-interaction --quiet
    COMPOSER_ROOT_VERSION="$version" composer install --no-interaction --no-progress --quiet
    if [ "$checks" = 1 ]; then
        vendor/bin/phpcs -q
        vendor/bin/phpstan analyse --memory-limit=1G --no-progress
        vendor/bin/phpunit --no-progress
    fi
)

# What WordPress runs, and nothing else.
cp "$source"/{postilio-for-wordpress.php,uninstall.php,readme.txt,LICENSE} "$stage/"
cp -r "$source"/{src,assets,languages} "$stage/"
# Of the prefixed dependencies: the autoloader, and per package its code and its license.
mkdir -p "$stage/vendor-prefixed"
cp -r "$source/vendor-prefixed/autoload.php" "$source/vendor-prefixed/composer" "$stage/vendor-prefixed/"
packages=()
for dir in "$source"/vendor-prefixed/*/*/; do
    package=${dir#"$source/vendor-prefixed/"}
    package=${package%/}
    packages+=("$package")
    mkdir -p "$stage/vendor-prefixed/$package"
    cp -r "$dir/src" "$stage/vendor-prefixed/$package/"
    if [ ! -f "$dir/LICENSE" ]; then
        echo "The license of $package is missing." >&2
        exit 1
    fi
    cp "$dir/LICENSE" "$stage/vendor-prefixed/$package/"
done

# The checks on the package.
cd "$work"
find postilio-for-wordpress -type f | LC_ALL=C sort > files
unexpected=$(grep -v -E '^postilio-for-wordpress/((postilio-for-wordpress|uninstall)\.php|readme\.txt|LICENSE|src/[A-Za-z]+\.php|assets/admin\.js|languages/postilio-for-wordpress(-nl_NL)?\.(pot|po|mo|l10n\.php)|vendor-prefixed/(autoload\.php|composer/[A-Za-z_]+\.(php|json)|composer/LICENSE|[a-z0-9-]+/[a-z0-9-]+/(LICENSE|src/.+\.php)))$' files || true)
if [ -n "$unexpected" ]; then
    echo "The ZIP would hold files it should not:" >&2
    echo "$unexpected" >&2
    exit 1
fi
if [ "${packages[*]}" != "nyholm/psr7 postilio/postilio-php psr/http-client psr/http-factory psr/http-message" ]; then
    echo "The prefixed dependencies changed (${packages[*]}): check their licenses, then update this list." >&2
    exit 1
fi
if grep -r -l -E '^namespace (Psr|Nyholm|Postilio|Http)\\' postilio-for-wordpress/vendor-prefixed; then
    echo "A dependency is not prefixed: it could clash with another plug-in's copy." >&2
    exit 1
fi
if grep -r -l -E 'pk_(live|test)_[A-Za-z0-9]{32}|whsec_[A-Za-z0-9+/=]{24,}' postilio-for-wordpress; then
    echo "The ZIP would hold something that looks like an API key or a webhook secret." >&2
    exit 1
fi
while read -r file; do
    case "$file" in *.php) php -l "$file" > /dev/null ;; esac
done < files

# Reproducible: fixed order, times of the commit, no extra attributes, plain permissions.
epoch=$(git -C "$OLDPWD" log -1 --format=%ct)
find postilio-for-wordpress -type d -exec chmod 755 {} +
find postilio-for-wordpress -type f -exec chmod 644 {} +
find postilio-for-wordpress -exec touch -h -d "@$epoch" {} +
zip_name="postilio-for-wordpress-$version.zip"
TZ=UTC zip -q -X -D -@ "$zip_name" < files
cd "$OLDPWD"
mkdir -p dist
mv "$work/$zip_name" "dist/$zip_name"
echo "OK: dist/$zip_name, $(wc -l < "$work/files") files, sha256 $(sha256sum "dist/$zip_name" | cut -d' ' -f1)"
