<?php
/**
 * User aggregate collector.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects role counts without personal identifiers.
 */
final class ODSC_Collector_Users {
	/**
	 * Collects aggregate user counts by role.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_role_counts() {
		$counts = count_users();
		$roles  = array();

		if ( isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ) {
			foreach ( $counts['avail_roles'] as $role => $count ) {
				$roles[ sanitize_key( $role ) ] = (int) $count;
			}
		}

		return ODSC_Sanitizer::result(
			'WP-11',
			'collected',
			'wordpress_user_counts',
			array(
				'total_users' => isset( $counts['total_users'] ) ? (int) $counts['total_users'] : array_sum( $roles ),
				'role_counts' => $roles,
			),
			__( '氏名、メールアドレス、ログインID、ユーザーIDは収集していません。', 'od-site-check' )
		);
	}
}
