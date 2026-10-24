<?php
/**
 * 固定ヒアリング定義と完成回答の検証。
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 自己申告を診断結果とは別に扱う。
 */
final class ODSC_Hearing {
	const VERSION = '1.0';

	/**
	 * 画面・検証・レポート用の共通定義。
	 *
	 * @return array<string, mixed>
	 */
	public static function definition() {
		static $definition;
		if ( null === $definition ) {
			$definition = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/hearing-1.0.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱した固定定義のみを読む。
		}
		return is_array( $definition ) ? $definition : array();
	}

	/**
	 * 定義全20問が揃っているか。
	 *
	 * @return bool
	 */
	public static function has_definitions() {
		$definition = self::definition();
		if ( ! isset( $definition['schema_version'], $definition['questions'] ) || self::VERSION !== $definition['schema_version'] ) {
			return false;
		}
		$expected = array();
		for ( $i = 1; $i <= 20; ++$i ) {
			$expected[] = sprintf( 'H-%02d', $i );
		}
		return array_column( $definition['questions'], 'id' ) === $expected;
	}

	/**
	 * 同名オブジェクトキーの上書きを避け、重複IDを明示的に拒否する。
	 *
	 * @param mixed $rows [ID, status, answer] のリスト.
	 * @return array|false
	 */
	public static function answers_from_rows( $rows ) {
		if ( ! is_array( $rows ) || range( 0, 19 ) !== array_keys( $rows ) ) {
			return false;
		}
		$answers = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array( 0, 1, 2 ) !== array_keys( $row ) || ! is_string( $row[0] ) || isset( $answers[ $row[0] ] ) ) {
				return false;
			}
			$answers[ $row[0] ] = array(
				'status' => $row[1],
				'answer' => $row[2],
			);
		}
		return self::is_valid_answers( $answers ) ? $answers : false;
	}

	/**
	 * 条件分岐と明示回答を検証する。未回答を補完しない。
	 *
	 * @param mixed $answers 全20問の回答.
	 * @return bool
	 */
	public static function is_valid_answers( $answers ) {
		if ( ! self::has_definitions() || ! is_array( $answers ) || 20 !== count( $answers ) ) {
			return false;
		}
		$definition = self::definition();
		foreach ( $definition['questions'] as $question ) {
			$id = $question['id'];
			if ( ! isset( $answers[ $id ] ) || ! is_array( $answers[ $id ] ) ) {
				return false;
			}
			$entry = $answers[ $id ];
			$keys  = array_keys( $entry );
			sort( $keys );
			if ( array( 'answer', 'status' ) !== $keys ) {
				return false;
			}
			$applicable = null === $question['condition'] || 'yes' === ( $answers['H-05']['answer'] ?? null );
			if ( ! $applicable ) {
				if ( 'not_applicable' !== $entry['status'] || null !== $entry['answer'] ) {
					return false;
				}
			} elseif ( 'answered' !== $entry['status'] || ! is_string( $entry['answer'] ) || ! array_key_exists( $entry['answer'], $question['options'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 出力要求時の完成スナップショットを作る。
	 *
	 * @param array $answers 検証済み回答.
	 * @return array
	 * @throws InvalidArgumentException 不正回答の場合.
	 */
	public static function create( $answers ) {
		if ( ! self::is_valid_answers( $answers ) ) {
			throw new InvalidArgumentException( 'ヒアリングの回答が不正です。' );
		}
		return array(
			'schema_version' => self::VERSION,
			'completed_at'   => wp_date( DATE_ATOM ),
			'answers'        => $answers,
		);
	}

	/**
	 * 完成した自己申告の形式を検証する。
	 *
	 * @param mixed $hearing 自己申告.
	 * @return bool
	 */
	public static function is_valid( $hearing ) {
		if ( ! is_array( $hearing ) ) {
			return false;
		}
		$keys = array_keys( $hearing );
		sort( $keys );
		if ( array( 'answers', 'completed_at', 'schema_version' ) !== $keys || self::VERSION !== $hearing['schema_version'] || ! is_string( $hearing['completed_at'] ) ) {
			return false;
		}
		$date = DateTimeImmutable::createFromFormat( DATE_ATOM, $hearing['completed_at'] );
		return false !== $date && $date->format( DATE_ATOM ) === $hearing['completed_at'] && self::is_valid_answers( $hearing['answers'] );
	}
}
