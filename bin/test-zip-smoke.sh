#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
config='.wp-env.smoke.json'
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$project_root/od-site-check.php" | head -n 1)
archive="/var/www/html/odsc-source/build/od-site-check-$version.zip"
environment_started=0

cleanup() {
	if [ "$environment_started" -eq 1 ]; then
		(
			cd "$project_root"
			npx wp-env --config "$config" destroy --force
		) >/dev/null 2>&1 || true
	fi
}

trap cleanup EXIT HUP INT TERM

cd "$project_root"
sh bin/build-zip.sh
sh bin/verify-zip.sh
npx wp-env --config "$config" destroy --force >/dev/null 2>&1 || true
environment_started=1
npx wp-env --config "$config" start

npx wp-env --config "$config" run cli wp plugin install "$archive" --activate
npx wp-env --config "$config" run cli wp eval-file /var/www/html/odsc-source/tests/smoke/zip-install-smoke.php

printf '%s\n' '新規の最小対応環境で、配布ZIPのインストール・有効化・診断画面を確認しました。'
