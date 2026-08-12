#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(sed -n "s/^define('CONFIGOPS_VERSION', '\([^']*\)');$/\1/p" "$repository_root/configops.php")"

if [[ -z "$version" ]]; then
	echo "Could not read CONFIGOPS_VERSION from configops.php." >&2
	exit 1
fi

staging_root="$(mktemp -d)"
trap 'rm -rf "$staging_root"' EXIT

plugin_root="$staging_root/configops"
archive_root="$repository_root/dist"
archive="$archive_root/configops-$version.zip"

mkdir -p "$plugin_root" "$archive_root"

cp "$repository_root/configops.php" "$plugin_root/configops.php"
cp "$repository_root/readme.txt" "$plugin_root/readme.txt"
cp "$repository_root/README.md" "$plugin_root/README.md"
cp -R "$repository_root/src" "$plugin_root/src"
cp -R "$repository_root/templates" "$plugin_root/templates"
cp -R "$repository_root/assets" "$plugin_root/assets"

find "$plugin_root" -name '.DS_Store' -delete
find "$plugin_root" -exec touch -t 202608120000 {} +

rm -f "$archive"
(
	cd "$staging_root"
	LC_ALL=C find configops -print | LC_ALL=C sort | zip -q -X "$archive" -@
)

entries="$(unzip -Z1 "$archive")"
for required in \
	configops/configops.php \
	configops/readme.txt \
	configops/src/Plugin.php \
	configops/assets/admin.css \
	configops/assets/ui/runtime.js; do
	if ! grep -qx "$required" <<< "$entries"; then
		echo "Release archive is missing $required." >&2
		exit 1
	fi
done

if grep -Eq '^configops/(tests|ui|build|node_modules|\.git|\.github|\.design-review|artifacts|vendor)/' <<< "$entries"; then
	echo "Release archive contains development-only files." >&2
	exit 1
fi

printf '%s\n' "$archive"
