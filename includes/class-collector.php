<?php
/**
 * Diagnostic collection orchestrator.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs all diagnostic items independently.
 */
final class ODSC_Collector {
	/**
	 * Returns all required diagnostic IDs in output order.
	 *
	 * @return string[]
	 */
	public static function expected_ids() {
		return array(
			'WP-01',
			'WP-02',
			'WP-03',
			'WP-04',
			'WP-07',
			'WP-08',
			'WP-09',
			'WP-10',
			'WP-11',
			'WP-12',
			'WP-13',
			'WP-15',
			'OPS-01',
			'OPS-03',
			'OPS-04',
			'OPS-05',
			'OPS-10',
			'OPS-11',
			'OPS-12',
			'OPS-14',
			'OPS-18',
			'OPS-19',
		);
	}

	/**
	 * Returns diagnostic item labels keyed by ID.
	 *
	 * @return array<string, string>
	 */
	public static function item_labels() {
		return array(
			'WP-01'  => __( 'WordPressバージョン', 'od-site-check' ),
			'WP-02'  => __( '更新設定', 'od-site-check' ),
			'WP-03'  => __( 'サイトヘルス', 'od-site-check' ),
			'WP-04'  => __( '使用テーマ', 'od-site-check' ),
			'WP-07'  => __( '有効プラグイン', 'od-site-check' ),
			'WP-08'  => __( '無効プラグイン', 'od-site-check' ),
			'WP-09'  => __( '独自開発コード', 'od-site-check' ),
			'WP-10'  => __( '更新上の懸念', 'od-site-check' ),
			'WP-11'  => __( 'ユーザー権限', 'od-site-check' ),
			'WP-12'  => __( 'ログイン保護', 'od-site-check' ),
			'WP-13'  => __( '一般設定', 'od-site-check' ),
			'WP-15'  => __( '表示設定', 'od-site-check' ),
			'OPS-01' => __( 'サーバー契約', 'od-site-check' ),
			'OPS-03' => __( 'PHP', 'od-site-check' ),
			'OPS-04' => __( 'データベース', 'od-site-check' ),
			'OPS-05' => __( '容量・リソース', 'od-site-check' ),
			'OPS-10' => __( 'バックアップ対象', 'od-site-check' ),
			'OPS-11' => __( 'バックアップ方式', 'od-site-check' ),
			'OPS-12' => __( 'バックアップ頻度', 'od-site-check' ),
			'OPS-14' => __( 'バックアップ取得履歴', 'od-site-check' ),
			'OPS-18' => __( '更新作業の運用方法', 'od-site-check' ),
			'OPS-19' => __( '障害対応体制', 'od-site-check' ),
		);
	}

	/**
	 * Returns IDs that always require operator input.
	 *
	 * @return string[]
	 */
	public static function manual_ids() {
		return array( 'OPS-01', 'OPS-10', 'OPS-12', 'OPS-14', 'OPS-18', 'OPS-19' );
	}

	/**
	 * Runs the complete diagnosis.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException       Internally converted to an item-level error result.
	 * @throws UnexpectedValueException Internally converted to an item-level error result.
	 */
	public function collect() {
		$is_multisite = (bool) apply_filters( 'odsc_is_multisite', is_multisite() );
		$tasks        = $this->get_tasks();
		$results      = array();

		foreach ( self::expected_ids() as $id ) {
			if ( $is_multisite && 0 !== strpos( $id, 'OPS-' ) ) {
				$results[] = ODSC_Sanitizer::result(
					$id,
					'unsupported',
					null,
					null,
					__( 'マルチサイトは初期版の診断対象外です。', 'od-site-check' )
				);
				continue;
			}

			try {
				if ( ! isset( $tasks[ $id ] ) || ! is_callable( $tasks[ $id ] ) ) {
					throw new RuntimeException( 'Collector unavailable.' );
				}

				$result = call_user_func( $tasks[ $id ] );

				if ( ! is_array( $result ) || $id !== $result['id'] ) {
					throw new UnexpectedValueException( 'Collector returned an invalid record.' );
				}

				$results[] = $result;
			} catch ( Throwable $throwable ) {
				$results[] = ODSC_Sanitizer::result(
					$id,
					'error',
					null,
					null,
					__( 'この項目の取得中にエラーが発生しました。ほかの項目は引き続き収集されています。', 'od-site-check' ),
					'collector_failed'
				);
			}
		}

		return array(
			'schema_version' => ODSC_SCHEMA_VERSION,
			'collector'      => array(
				'name'    => 'OD Site Check',
				'version' => ODSC_VERSION,
			),
			'collected_at'   => current_time( DATE_RFC3339 ),
			'site'           => array(
				'url'       => home_url( '/' ),
				'multisite' => $is_multisite,
			),
			'results'        => $results,
		);
	}

	/**
	 * Returns one independently callable task per diagnostic ID.
	 *
	 * @return array<string, callable>
	 */
	private function get_tasks() {
		$wordpress   = new ODSC_Collector_WordPress();
		$themes      = new ODSC_Collector_Themes();
		$plugins     = new ODSC_Collector_Plugins();
		$users       = new ODSC_Collector_Users();
		$settings    = new ODSC_Collector_Settings();
		$environment = new ODSC_Collector_Environment();

		$manual = static function ( $id, $note ) {
			return static function () use ( $id, $note ) {
				return ODSC_Sanitizer::result( $id, 'manual_required', null, null, $note );
			};
		};

		$tasks = array(
			'WP-01'  => array( $wordpress, 'collect_version' ),
			'WP-02'  => array( $wordpress, 'collect_update_settings' ),
			'WP-03'  => array( $wordpress, 'collect_site_health' ),
			'WP-04'  => array( $themes, 'collect_active_theme' ),
			'WP-07'  => array( $plugins, 'collect_active_plugins' ),
			'WP-08'  => array( $plugins, 'collect_inactive_plugins' ),
			'WP-09'  => array( $plugins, 'collect_custom_code_indicators' ),
			'WP-10'  => array( $wordpress, 'collect_pending_updates' ),
			'WP-11'  => array( $users, 'collect_role_counts' ),
			'WP-12'  => array( $plugins, 'collect_login_protection_plugins' ),
			'WP-13'  => array( $settings, 'collect_general_settings' ),
			'WP-15'  => array( $settings, 'collect_reading_settings' ),
			'OPS-01' => $manual( 'OPS-01', __( 'サーバー契約内容は別途確認が必要です。', 'od-site-check' ) ),
			'OPS-03' => array( $environment, 'collect_php' ),
			'OPS-04' => array( $environment, 'collect_database' ),
			'OPS-05' => array( $environment, 'collect_resources' ),
			'OPS-10' => $manual( 'OPS-10', __( 'バックアップ対象は別途確認が必要です。', 'od-site-check' ) ),
			'OPS-11' => array( $plugins, 'collect_backup_plugins' ),
			'OPS-12' => $manual( 'OPS-12', __( 'バックアップ頻度は別途確認が必要です。', 'od-site-check' ) ),
			'OPS-14' => $manual( 'OPS-14', __( 'バックアップ取得履歴は別途確認が必要です。', 'od-site-check' ) ),
			'OPS-18' => $manual( 'OPS-18', __( '更新作業の運用方法は別途確認が必要です。', 'od-site-check' ) ),
			'OPS-19' => $manual( 'OPS-19', __( '障害対応の体制は別途確認が必要です。', 'od-site-check' ) ),
		);

		/**
		 * Filters diagnostic tasks. Primarily intended for isolated testing.
		 *
		 * @param array<string, callable> $tasks Diagnostic task callbacks keyed by ID.
		 */
		return apply_filters( 'odsc_collectors', $tasks );
	}
}
