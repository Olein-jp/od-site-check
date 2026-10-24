<?php
/**
 * 定型ヒアリングの完成契約。
 *
 * @package ODSiteCheck
 */

use Opis\JsonSchema\Validator;

/**
 * PHPとJSON Schemaが同じ分岐・許可コードを検証する。
 */
class HearingTest extends WP_UnitTestCase {
	/**
	 * 完成JSONと内部診断を区別し、3つの分岐を検証する。
	 *
	 * @return void
	 */
	public function test_complete_contract_and_branches() {
		$schema   = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱Schema。
		$exporter = new ODSC_Exporter();
		$payload  = ( new ODSC_Collector() )->collect();
		$original = $payload['results'];
		$this->assertTrue( ODSC_Hearing::has_definitions() );
		$this->assertFalse( $exporter->is_valid_payload( $payload ) );
		$this->assertFalse( ( new Validator() )->validate( json_decode( wp_json_encode( $payload ) ), $schema )->isValid() );
		foreach ( array( 'no', 'unknown', 'yes' ) as $backup ) {
			$answers            = ODSC_Test_Hearing::answers( $backup );
			$payload['hearing'] = ODSC_Hearing::create( $answers );
			$this->assertTrue( $exporter->is_valid_payload( $payload ) );
			$this->assertTrue( ( new Validator() )->validate( json_decode( wp_json_encode( $payload ) ), $schema )->isValid() );
			$this->assertSame( $original, $payload['results'] );
			$this->assertCount( 20, $answers );
			$this->assertSame(
				'yes' === $backup ? 20 : 13,
				count(
					array_filter(
						$answers,
						static function ( $answer ) {
							return 'answered' === $answer['status'];
						}
					)
				)
			);
		}
	}

	/**
	 * 不正値をPHPとSchemaの両方で拒否する。
	 *
	 * @return void
	 */
	public function test_invalid_answers_are_rejected() {
		$schema  = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱Schema。
		$payload = ( new ODSC_Collector() )->collect();
		$valid   = ODSC_Test_Hearing::answers();
		$cases   = array();
		$case    = $valid;
		unset( $case['H-01'] );
		$cases[]      = $case;
		$case         = $valid;
		$case['H-21'] = $case['H-01'];
		$cases[]      = $case;
		foreach ( array( null, 'invalid', 1, array(), '<script>' ) as $value ) {
			$case                   = $valid;
			$case['H-01']['answer'] = $value;
			$cases[]                = $case;
		}
		$case                   = $valid;
		$case['H-01']['status'] = 'not_applicable';
		$cases[]                = $case;
		$case                   = $valid;
		$case['H-06']           = array(
			'status' => 'answered',
			'answer' => 'unknown',
		);
		$cases[]                = $case;
		$case                   = $valid;
		$case['H-05']['answer'] = 'yes';
		$cases[]                = $case;
		$case                   = $valid;
		$case['H-01']['extra']  = 'unknown';
		$cases[]                = $case;
		foreach ( $cases as $answers ) {
			$this->assertFalse( ODSC_Hearing::is_valid_answers( $answers ) );
			$payload['hearing'] = array(
				'schema_version' => '1.0',
				'completed_at'   => '2026-10-24T09:30:00+09:00',
				'answers'        => $answers,
			);
			$this->assertFalse( ( new ODSC_Exporter() )->is_valid_payload( $payload ) );
			$this->assertFalse( ( new Validator() )->validate( json_decode( wp_json_encode( $payload ) ), $schema )->isValid() );
		}
	}

	/**
	 * 配列形式で重複IDとキーの曖昧な補完を防ぐ。
	 *
	 * @return void
	 */
	public function test_transport_rejects_duplicates_and_malformed_rows() {
		$answers = ODSC_Test_Hearing::answers();
		$rows    = array();
		foreach ( $answers as $id => $answer ) {
			$rows[] = array( $id, $answer['status'], $answer['answer'] );
		}
		$this->assertSame( $answers, ODSC_Hearing::answers_from_rows( $rows ) );
		$duplicate    = $rows;
		$duplicate[1] = $duplicate[0];
		$this->assertFalse( ODSC_Hearing::answers_from_rows( $duplicate ) );
		$rows[0]['extra'] = 'value';
		$this->assertFalse( ODSC_Hearing::answers_from_rows( $rows ) );
		$this->assertFalse( ODSC_Hearing::answers_from_rows( null ) );
	}

	/**
	 * 版と確定日時を曖昧に受理しない。
	 *
	 * @return void
	 */
	public function test_hearing_metadata_is_strict() {
		$hearing = ODSC_Hearing::create( ODSC_Test_Hearing::answers() );
		$this->assertTrue( ODSC_Hearing::is_valid( $hearing ) );
		foreach ( array( '2026-02-30T09:30:00+09:00', '2026-10-24', '', null ) as $date ) {
			$case                 = $hearing;
			$case['completed_at'] = $date;
			$this->assertFalse( ODSC_Hearing::is_valid( $case ) );
		}
		$hearing['schema_version'] = '0.9';
		$this->assertFalse( ODSC_Hearing::is_valid( $hearing ) );
	}
	/**
	 * 共通定義と画面文面・radioが一致し、初期選択がない。
	 *
	 * @return void
	 */
	public function test_rendered_questions_match_definition() {
		$method = new ReflectionMethod( ODSC_Admin::class, 'render_hearing' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( ODSC_Admin::get_instance() );
		$html     = (string) ob_get_clean();
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $dom );
		$this->assertSame( 20, $xpath->query( '//fieldset' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@checked]' )->length );
		foreach ( ODSC_Hearing::definition()['questions'] as $question ) {
			$fieldset = $xpath->query( '//fieldset[@id="odsc-' . $question['id'] . '"]' )->item( 0 );
			$this->assertStringContainsString( $question['question'], $xpath->evaluate( 'string(.)', $fieldset ) );
			$this->assertSame( null !== $question['condition'], $fieldset->hasAttribute( 'hidden' ) );
			$this->assertSame( count( $question['options'] ), $xpath->query( './/input[@type="radio"]', $fieldset )->length );
			foreach ( $question['options'] as $code => $label ) {
				$this->assertSame( 1, $xpath->query( './/input[@value="' . $code . '"]', $fieldset )->length );
				$this->assertStringContainsString( $label, $xpath->evaluate( 'string(.)', $fieldset ) );
			}
		}
	}
	/**
	 * 各設問の全選択肢コードが配布Schemaと一致する。
	 *
	 * @return void
	 */
	public function test_every_defined_code_is_accepted_by_the_schema() {
		$schema  = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱Schema。
		$payload = ( new ODSC_Collector() )->collect();
		foreach ( ODSC_Hearing::definition()['questions'] as $question ) {
			foreach ( array_keys( $question['options'] ) as $code ) {
				$answers                              = ODSC_Test_Hearing::answers( 'H-05' === $question['id'] ? $code : 'yes' );
				$answers[ $question['id'] ]['answer'] = $code;
				$this->assertTrue( ODSC_Hearing::is_valid_answers( $answers ) );
				$payload['hearing'] = ODSC_Hearing::create( $answers );
				$this->assertTrue( ( new Validator() )->validate( json_decode( wp_json_encode( $payload ) ), $schema )->isValid(), $question['id'] . ':' . $code );
			}
		}
	}
	/**
	 * 配布する13問／20問のサンプルも完成Schemaに適合する。
	 *
	 * @return void
	 */
	public function test_shipped_samples_match_the_complete_schema() {
		$schema = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱Schema。
		foreach ( array( 'no', 'yes' ) as $backup ) {
			$data = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'docs/samples/hearing-' . $backup . '.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱サンプル。
			$this->assertTrue( ( new Validator() )->validate( $data, $schema )->isValid() );
			$this->assertTrue( ( new ODSC_Exporter() )->is_valid_payload( json_decode( wp_json_encode( $data ), true ) ) );
		}
	}
}
