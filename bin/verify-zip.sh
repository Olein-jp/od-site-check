#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$project_root/od-site-check.php" | head -n 1)
archive=${1:-"$project_root/build/od-site-check-$version.zip"}
work_dir=$(mktemp -d)
expected="$work_dir/expected.txt"
actual="$work_dir/actual.txt"

cleanup() {
	rm -rf "$work_dir"
}

trap cleanup EXIT HUP INT TERM

unzip -t "$archive"
sed 's#^#od-site-check/#' "$project_root/bin/package-files.txt" | sort > "$expected"
unzip -Z1 "$archive" | sed '/\/$/d' | sort > "$actual"

if ! diff -u "$expected" "$actual"; then
	printf '%s\n' '配布ZIPに許可リスト外または不足しているファイルがあります。' >&2
	exit 1
fi

if unzip -Z1 "$archive" | grep -E '(^|/)(\.env|\.git|vendor|node_modules|tests|bin)(/|$)' >/dev/null; then
	printf '%s\n' '配布ZIPに開発専用または秘密情報となり得るファイルが含まれています。' >&2
	exit 1
fi

printf '%s\n' '配布ZIPの整合性と内容を確認しました。'
