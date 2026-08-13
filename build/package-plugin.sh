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
archive_root="${CONFIGOPS_ARCHIVE_ROOT:-$repository_root/dist}"
archive="$archive_root/configops-$version.zip"

if [[ -z "$archive_root" || "$archive_root" == "/" || "$archive_root" == "$repository_root" ]]; then
	echo "Refusing to use an unsafe archive directory." >&2
	exit 1
fi

mkdir -p "$plugin_root" "$archive_root"

# Keep dist unambiguous for installation tests and human release uploads.
find "$archive_root" -maxdepth 1 -type f -name 'configops-*.zip' ! -name "$(basename "$archive")" -delete

cp "$repository_root/configops.php" "$plugin_root/configops.php"
cp "$repository_root/uninstall.php" "$plugin_root/uninstall.php"
cp "$repository_root/readme.txt" "$plugin_root/readme.txt"
cp "$repository_root/CHANGELOG.md" "$plugin_root/CHANGELOG.md"
cp "$repository_root/LICENSE" "$plugin_root/LICENSE"
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
	configops/uninstall.php \
	configops/readme.txt \
	configops/LICENSE \
	configops/CHANGELOG.md \
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

if grep -Eqi '(^|/)(codex|chatgpt|claude|prompt)([._/-]|$)|\.map$' <<< "$entries"; then
	echo "Release archive contains an assistant, prompt, or source-map artifact." >&2
	exit 1
fi

if find "$plugin_root/assets/ui" -type f -name '*.js' -exec cat {} + \
	| grep -Eqi '\bcodex\b|\bchatgpt\b|\bclaude\b|\banthropic\b|\bopenai\b|system prompt|prompt injection|sourceMappingURL=|\beval[[:space:]]*\(|\bnew[[:space:]]+Function[[:space:]]*\('; then
	echo "Release JavaScript contains an assistant artifact, source map, or dynamic-code pattern." >&2
	exit 1
fi

printf '%s\n' "$archive"
