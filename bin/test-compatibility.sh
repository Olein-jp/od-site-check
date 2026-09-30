#!/bin/sh

set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
active_config=''

cleanup() {
	if [ -n "$active_config" ]; then
		(
			cd "$project_root"
			npx wp-env --config "$active_config" stop
		) >/dev/null 2>&1 || true
	fi
}

trap cleanup EXIT HUP INT TERM

run_suite() {
	config=$1
	environment_label=$2
	expected_php=$3
	active_config=$config

	printf '\n%s\n' "互換性テストを開始します: $environment_label"
	(
		cd "$project_root"
		npx wp-env --config "$config" start
		npx wp-env --config "$config" run cli wp eval "global \$wp_version; if (version_compare(\$wp_version, '7.1', '<')) { WP_CLI::error('WordPress version is below 7.1: ' . \$wp_version); } if (0 !== strpos(PHP_VERSION, '$expected_php')) { WP_CLI::error('PHP version mismatch: ' . PHP_VERSION); } WP_CLI::success('WordPress ' . \$wp_version . ' / PHP ' . PHP_VERSION);"
		npx wp-env --config "$config" run tests-cli --env-cwd=wp-content/plugins/od-site-check vendor/bin/phpunit
		npx wp-env --config "$config" stop
	)

	active_config=''
}

run_suite '.wp-env.compat-min.json' 'WordPress 7.1 / PHP 7.4' '7.4'
run_suite '.wp-env.compat-current.json' '現行安定版WordPress / PHP 8.3' '8.3'

printf '\n%s\n' '宣言した最小環境と代表的な現行環境でテストが完了しました。'
