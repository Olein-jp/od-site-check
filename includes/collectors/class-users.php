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
 * 権限別人数と、許可された利用者向けのアカウント一覧を取得する。
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

		$can_list = current_user_can( 'list_users' );
		$users    = null;
		if ( $can_list ) {
			$users = array();
			foreach ( get_users(
				array(
					'fields'  => array( 'ID', 'user_login' ),
					'orderby' => 'ID',
				)
			) as $account ) {
				$user    = new WP_User( $account->ID );
				$users[] = array(
					'user_login' => $account->user_login,
					'roles'      => array_values( $user->roles ),
				);
			}
		}

		return ODSC_Sanitizer::result(
			'WP-11',
			$can_list ? 'collected' : 'partial',
			'wordpress_user_counts',
			array(
				'total_users'      => isset( $counts['total_users'] ) ? (int) $counts['total_users'] : array_sum( $roles ),
				'role_counts'      => $roles,
				'users'            => $users,
				'users_error_code' => $can_list ? null : 'list_users_forbidden',
			),
			$can_list ? __( 'アカウント名（ログイン名）と権限グループを収集しました。氏名、メールアドレス、ユーザーID、パスワードは出力しません。', 'od-site-check' ) : __( 'list_users権限がないため、アカウント一覧を取得できません。権限別人数のみ取得しました。', 'od-site-check' )
		);
	}
}
