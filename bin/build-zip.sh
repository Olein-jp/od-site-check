#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$project_root/od-site-check.php" | head -n 1)
archive="$project_root/build/od-site-check-$version.zip"
staging=$(mktemp -d)
plugin_dir="$staging/od-site-check"

cleanup() {
	rm -rf "$staging"
}

trap cleanup EXIT HUP INT TERM

mkdir -p "$plugin_dir" "$project_root/build"

for item in od-site-check.php includes assets schemas uninstall.php readme.txt README.md; do
	cp -R "$project_root/$item" "$plugin_dir/$item"
done

(
	cd "$staging"
	zip -qrFS "$archive" od-site-check
)

printf '%s\n' "$archive"
