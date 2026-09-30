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

while IFS= read -r item; do
	case "$item" in
		''|'#'*) continue ;;
	esac

	mkdir -p "$plugin_dir/$(dirname -- "$item")"
	cp "$project_root/$item" "$plugin_dir/$item"
done < "$project_root/bin/package-files.txt"

# 繰り返し生成しても同一のZIPになるよう、アーカイブ内の日時を固定する。
find "$plugin_dir" -exec touch -t 198001010000 {} +

(
	cd "$staging"
	zip -qrFSX "$archive" od-site-check
)

printf '%s\n' "$archive"
