<?php
/**
 * 本体自動更新設定の静的な契約を検証する。
 *
 * @package ODSiteCheck
 */

use Opis\JsonSchema\Validator;

/**
 * 設定を変更・実行せず、参照値と未検証を区別する。
 */
class CoreUpdateSettingsTest extends WP_UnitTestCase {
	/**
	 * テスト中に隔離するフック。
	 *
	 * @var string[]
	 */
	private const HOOKS = array( 'automatic_updater_disabled', 'allow_major_auto_core_updates', 'allow_minor_auto_core_updates', 'allow_dev_auto_core_updates', 'auto_update_core' );

	/**
	 * 元のフックを保持する。
	 *
	 * @var array
	 */
	private $original_hooks = array();

	/**
	 * フックのない制御ケースを用意する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wp_filter;
		foreach ( self::HOOKS as $hook ) {
			$this->original_hooks[ $hook ] = $wp_filter[ $hook ] ?? null;
			unset( $wp_filter[ $hook ] );
		}
		foreach ( array( 'major', 'minor', 'dev' ) as $type ) {
			delete_site_option( 'auto_update_core_' . $type );
		}
	}

	/**
	 * 他テストへフック登録状態を漏らさない。
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_filter;
		foreach ( $this->original_hooks as $hook => $original ) {
			if ( null === $original ) {
				unset( $wp_filter[ $hook ] );
			} else {
				$wp_filter[ $hook ] = $original;
			}
		}
		parent::tear_down();
	}

	/**
	 * APIの既定値と明示保存の同値を区別して断定しない。
	 *
	 * @return void
	 */
	public function test_defaults_and_saved_values() {
		$collector = new ODSC_Collector_WordPress();
		$result    = $collector->collect_update_settings();
		$details   = $result['value']['core_auto_update_details'];
		$this->assertSame(
			array(
				'major' => 'unset',
				'minor' => 'enabled',
				'dev'   => 'enabled',
			),
			$details['saved_options']
		);
		$this->assertSame(
			array(
				'major' => 'unknown',
				'minor' => 'enabled',
				'dev'   => 'enabled',
			),
			$details['declared_policy']
		);
		$this->assertSame( 'wordpress_default', $result['value']['core_auto_update'] );
		$this->assertSame( 'partial', $result['status'] );
		update_site_option( 'auto_update_core_major', 'enabled' );
		update_site_option( 'auto_update_core_minor', 'disabled' );
		update_site_option( 'auto_update_core_dev', 'private_unexpected_value' );
		$details = $collector->collect_update_settings()['value']['core_auto_update_details'];
		$this->assertSame(
			array(
				'major' => 'enabled',
				'minor' => 'disabled',
				'dev'   => 'custom',
			),
			$details['saved_options']
		);
		$this->assertSame(
			array(
				'major' => 'enabled',
				'minor' => 'disabled',
				'dev'   => 'unknown',
			),
			$details['declared_policy']
		);
		$this->assertStringNotContainsString( 'private_unexpected_value', wp_json_encode( $details ) );
		$this->assertFalse( $details['effective_behavior_verified'] );
	}

	/**
	 * 型違い・未知値を推測せずcustomにする。
	 *
	 * @return void
	 */
	public function test_strict_option_normalization() {
		update_site_option( 'auto_update_core_major', 'enabled' );
		foreach ( array( false, true, 1, '1', '', array( 'enabled' ), 'other' ) as $value ) {
			$filter = static function () use ( $value ) {
				return $value;
			};
			add_filter( 'option_auto_update_core_major', $filter );
			try {
				$details = ( new ODSC_Collector_WordPress() )->collect_update_settings()['value']['core_auto_update_details'];
				$this->assertSame( 'custom', $details['saved_options']['major'] );
				$this->assertSame( 'unknown', $details['declared_policy']['major'] );
			} finally {
				remove_filter( 'option_auto_update_core_major', $filter );
			}
		}
	}

	/**
	 * フックの存在だけを検出し、優先度0でも実行しない。
	 *
	 * @return void
	 */
	public function test_registered_filters_invalidate_only_the_relevant_categories() {
		update_site_option( 'auto_update_core_major', 'enabled' );
		foreach ( self::HOOKS as $hook ) {
			$called   = 0;
			$callback = static function () use ( &$called ) {
				++$called;
				throw new RuntimeException( '更新方針のフィルターは実行しない。' );
			};
			add_filter( $hook, $callback, 0 );
			$details = ( new ODSC_Collector_WordPress() )->collect_update_settings()['value']['core_auto_update_details'];
			$this->assertTrue( $details['registered_filters'][ $hook ] );
			$this->assertSame( 0, $called );
			foreach ( array( 'major', 'minor', 'dev' ) as $type ) {
				$affected = in_array( $hook, array( 'automatic_updater_disabled', 'auto_update_core', 'allow_' . $type . '_auto_core_updates' ), true );
				$this->assertSame( $affected ? 'unknown' : 'enabled', $details['declared_policy'][ $type ] );
			}
			remove_filter( $hook, $callback, 0 );
		}
	}

	/**
	 * 既知の定数指定と不明値をプロセス隔離して検証する。
	 *
	 * @dataProvider constant_cases
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param mixed $constant 定数の値.
	 * @param bool  $global_disabled 全体停止定数.
	 * @param array $expected 静的な指定.
	 * @return void
	 */
	public function test_constants_and_filter_precedence( $constant, $global_disabled, $expected ) {
		define( 'WP_AUTO_UPDATE_CORE', $constant );
		define( 'AUTOMATIC_UPDATER_DISABLED', $global_disabled );
		$collector = new ODSC_Collector_WordPress();
		$details   = $collector->collect_update_settings()['value']['core_auto_update_details'];
		$this->assertSame( $expected, array_values( $details['declared_policy'] ) );
		$this->assertSame( $global_disabled, $details['global_disable_constant'] );
		$this->assertSame( $collector->collect_update_settings()['value']['core_auto_update'], $details['constant_policy'] );
		$callback = '__return_true';
		add_filter( 'auto_update_core', $callback );
		$details = $collector->collect_update_settings()['value']['core_auto_update_details'];
		$this->assertSame( array( 'unknown', 'unknown', 'unknown' ), array_values( $details['declared_policy'] ) );
		$this->assertFalse( $details['effective_behavior_verified'] );
	}

	/**
	 * 判断表の各定数ケース。
	 *
	 * @return array
	 */
	public function constant_cases() {
		return array(
			array( true, false, array( 'enabled', 'enabled', 'enabled' ) ),
			array( false, false, array( 'disabled', 'disabled', 'disabled' ) ),
			array( 'minor', false, array( 'disabled', 'enabled', 'disabled' ) ),
			array( 'unknown', false, array( 'unknown', 'unknown', 'unknown' ) ),
			array( 'beta', false, array( 'unknown', 'unknown', 'unknown' ) ),
			array( true, true, array( 'disabled', 'disabled', 'disabled' ) ),
		);
	}

	/**
	 * 新Schemaが追加オブジェクトの不正な契約を拒否する。
	 *
	 * @return void
	 */
	public function test_schema_validates_core_update_details() {
		$schema           = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱Schema。
		$schema->{'$ref'} = '#/definitions/wp02';
		$value            = ( new ODSC_Collector_WordPress() )->collect_update_settings()['value'];
		$validator        = new Validator();
		$this->assertTrue( $validator->validate( json_decode( wp_json_encode( $value ) ), $schema )->isValid() );
		$invalid = array();
		foreach ( array_keys( $value['core_auto_update_details'] ) as $key ) {
			$case = $value;
			unset( $case['core_auto_update_details'][ $key ] );
			$invalid[] = $case;
		}
		$case = $value;
		$case['core_auto_update_details']['effective_behavior_verified'] = true;
		$invalid[] = $case;
		$case      = $value;
		$case['core_auto_update_details']['saved_options']['major'] = 'invalid';
		$invalid[] = $case;
		$case      = $value;
		$case['core_auto_update_details']['registered_filters']['auto_update_core'] = 'true';
		$invalid[] = $case;
		$case      = $value;
		unset( $case['core_auto_update_details'] );
		$invalid[] = $case;
		foreach ( $invalid as $case ) {
			$this->assertFalse( $validator->validate( json_decode( wp_json_encode( $case ) ), $schema )->isValid() );
		}
	}

	/**
	 * 旧1.3の成果物は旧Schemaで引き続き読める。
	 *
	 * @return void
	 */
	public function test_legacy_schema_and_samples_are_preserved() {
		$schema = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result-1.3.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 保存した旧Schema。
		foreach ( array( 'no', 'yes' ) as $branch ) {
			$data = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'tests/fixtures/diagnostic-0.4.0-' . $branch . '.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 旧成果物。
			$this->assertTrue( ( new Validator() )->validate( $data, $schema )->isValid() );
		}
	}
}
