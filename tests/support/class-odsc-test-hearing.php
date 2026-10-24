<?php
/**
 * ヒアリングテスト用の完成回答。
 *
 * @package ODSiteCheck
 */

/**
 * 13問／20問の回答例を作る。
 */
final class ODSC_Test_Hearing {
	/**
	 * 全問unknown、H-05のみ引数の値にする。
	 *
	 * @param string $backup バックアップ回答.
	 * @return array
	 */
	public static function answers( $backup = 'no' ) {
		$answers = array();
		foreach ( ODSC_Hearing::definition()['questions'] as $question ) {
			$applicable                 = null === $question['condition'] || 'yes' === $backup;
			$answers[ $question['id'] ] = array(
				'status' => $applicable ? 'answered' : 'not_applicable',
				'answer' => $applicable ? ( 'H-05' === $question['id'] ? $backup : 'unknown' ) : null,
			);
		}
		return $answers;
	}
}
