<?php
/**
 * Administration screen and request handlers.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the Tools > OD Site Check workflow.
 */
final class ODSC_Admin {
	/**
	 * Singleton instance.
	 *
	 * @var ODSC_Admin|null
	 */
	private static $instance = null;

	/**
	 * Result generated during the current request only.
	 *
	 * @var array<string, mixed>|null
	 */
	private $result = null;

	/**
	 * Page-specific error shown during the current request.
	 *
	 * @var string|null
	 */
	private $error = null;

	/**
	 * Exporter used for signed JSON responses.
	 *
	 * @var ODSC_Exporter|null
	 */
	private $exporter = null;

	/**
	 * Gets the singleton instance.
	 *
	 * @return ODSC_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_odsc_download', array( $this, 'handle_download' ) );
	}

	/**
	 * Registers the Tools submenu.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		$hook = add_management_page(
			__( 'OD サイト診断', 'od-site-check' ),
			__( 'OD サイト診断', 'od-site-check' ),
			'manage_options',
			'od-site-check',
			array( $this, 'render_page' )
		);

		add_action( 'load-' . $hook, array( $this, 'handle_run_request' ) );
	}

	/**
	 * Loads page assets only on this plugin's screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_od-site-check' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'odsc-admin', ODSC_PLUGIN_URL . 'assets/admin.css', array(), ODSC_VERSION );
		wp_enqueue_script( 'odsc-admin', ODSC_PLUGIN_URL . 'assets/admin.js', array(), ODSC_VERSION, true );
		wp_localize_script(
			'odsc-admin',
			'odscAdmin',
			array(
				'checking'     => __( '確認しています…', 'od-site-check' ),
				'checkingNote' => __( 'サイトの情報を確認しています。この画面を閉じずにお待ちください。', 'od-site-check' ),
				'copied'       => __( 'JSONをコピーしました。', 'od-site-check' ),
				'copyFailed'   => __( 'コピーできませんでした。JSON欄を選択してコピーしてください。', 'od-site-check' ),
			)
		);
	}

	/**
	 * Processes a diagnosis POST before the page renders.
	 *
	 * @return void
	 */
	public function handle_run_request() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}

		$action = isset( $_POST['odsc_action'] ) ? sanitize_key( wp_unslash( $_POST['odsc_action'] ) ) : '';
		if ( 'run' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この診断を実行する権限がありません。', 'od-site-check' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'odsc_run_diagnostic', '_odsc_nonce' );

		$consent = isset( $_POST['odsc_consent'] ) ? sanitize_key( wp_unslash( $_POST['odsc_consent'] ) ) : '';
		if ( '1' !== $consent ) {
			$this->error = __( '収集内容をご確認のうえ、チェックボックスを選択してください。', 'od-site-check' );
			return;
		}

		$this->result = ( new ODSC_Collector() )->collect();
	}

	/**
	 * Streams a previously generated, signed result.
	 *
	 * @return void
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '診断結果をダウンロードする権限がありません。', 'od-site-check' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'odsc_download_result', '_odsc_download_nonce' );

		$encoded   = isset( $_POST['odsc_payload'] ) ? sanitize_text_field( wp_unslash( $_POST['odsc_payload'] ) ) : '';
		$signature = isset( $_POST['odsc_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['odsc_signature'] ) ) : '';
		$json      = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a signed JSON transport value.
		$exporter  = $this->get_exporter();

		if ( false === $json || ! $exporter->verify( $json, $signature ) ) {
			wp_die( esc_html__( '診断結果を確認できませんでした。もう一度診断を実行してください。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || ! $exporter->is_valid_payload( $payload ) ) {
			wp_die( esc_html__( '診断結果の形式が正しくありません。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}

		$exporter->download( $json );
	}

	/**
	 * Renders the administration screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'od-site-check' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap odsc-wrap">
			<h1><?php esc_html_e( 'OD サイト診断', 'od-site-check' ); ?></h1>

			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'このサイトはマルチサイトです。初期版では未対応のため、WordPress内部の項目は「未対応」として記録されます。', 'od-site-check' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $this->error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $this->error ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $this->result ) ) : ?>
				<?php $this->render_result( $this->result ); ?>
			<?php else : ?>
				<?php $this->render_intro(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the pre-flight explanation and consent form.
	 *
	 * @return void
	 */
	private function render_intro() {
		?>
		<div class="odsc-card">
			<h2><?php esc_html_e( 'この診断について', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( 'WordPressのバージョン、テーマ、プラグイン、設定、PHPやデータベースなど、サイト改善の検討に必要な情報を収集します。', 'od-site-check' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'パスワード、APIキー、認証情報、ユーザーの氏名・メールアドレスは収集しません。', 'od-site-check' ); ?></li>
				<li><?php esc_html_e( 'サイトの設定変更、更新、修復、削除、バックアップは行いません。', 'od-site-check' ); ?></li>
				<li><?php esc_html_e( '診断結果を外部サーバーへ自動送信しません。', 'od-site-check' ); ?></li>
				<li><?php esc_html_e( '取得できない契約・運用情報は、手動確認が必要な項目として記録します。', 'od-site-check' ); ?></li>
			</ul>
			<p><?php esc_html_e( '診断後にJSONファイルをダウンロードし、オレインデザインへお送りください。調査終了後は、このプラグインを無効化・削除してください。', 'od-site-check' ); ?></p>
		</div>

		<form method="post" id="odsc-run-form" class="odsc-card">
			<input type="hidden" name="odsc_action" value="run">
			<?php wp_nonce_field( 'odsc_run_diagnostic', '_odsc_nonce' ); ?>

			<label class="odsc-consent">
				<input type="checkbox" name="odsc_consent" id="odsc-consent" value="1">
				<?php esc_html_e( '収集する情報と収集しない情報を確認しました。', 'od-site-check' ); ?>
			</label>

			<p class="submit">
				<button type="submit" id="odsc-run-button" class="button button-primary button-hero" disabled>
					<?php esc_html_e( 'サイトの状態を確認する', 'od-site-check' ); ?>
				</button>
			</p>
			<p id="odsc-progress" class="odsc-progress" role="status" aria-live="polite"></p>
		</form>
		<?php
	}

	/**
	 * Renders summary counts and the secure download form.
	 *
	 * @param array<string, mixed> $result Diagnostic result.
	 * @return void
	 */
	private function render_result( $result ) {
		$summary = array(
			'collected' => 0,
			'missing'   => 0,
			'manual'    => 0,
			'error'     => 0,
		);

		foreach ( $result['results'] as $item ) {
			switch ( $item['status'] ) {
				case 'collected':
					++$summary['collected'];
					break;
				case 'manual_required':
					++$summary['manual'];
					break;
				case 'error':
					++$summary['error'];
					break;
				default:
					++$summary['missing'];
			}
		}

		$exporter  = $this->get_exporter();
		$json      = $exporter->encode( $result );
		$labels    = ODSC_Collector::item_labels();
		$guidance  = $this->get_interview_guidance();
		$timestamp = strtotime( $result['collected_at'] );
		$timestamp = false === $timestamp ? time() : $timestamp;
		$site_time = wp_date( 'Y年n月j日 H:i:s T', $timestamp, wp_timezone() );
		$utc_time  = gmdate( 'Y-m-d H:i:s \U\T\C', $timestamp );
		?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'サイトの状態確認が完了しました。', 'od-site-check' ); ?></p></div>
		<div class="odsc-card">
			<h2><?php esc_html_e( '診断結果の概要', 'od-site-check' ); ?></h2>
			<dl class="odsc-summary">
				<div class="odsc-summary-time">
					<dt><?php esc_html_e( '診断日時', 'od-site-check' ); ?></dt>
					<dd>
						<span><strong><?php esc_html_e( 'サイト設定：', 'od-site-check' ); ?></strong><?php echo esc_html( $site_time ); ?></span>
						<span><strong><?php esc_html_e( 'UTC：', 'od-site-check' ); ?></strong><?php echo esc_html( $utc_time ); ?></span>
					</dd>
				</div>
				<div><dt><?php esc_html_e( '取得済み項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-collected"><?php echo esc_html( (string) $summary['collected'] ); ?></dd></div>
				<div><dt><?php esc_html_e( '一部取得・未取得項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-missing"><?php echo esc_html( (string) $summary['missing'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'ヒアリング確認項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-manual"><?php echo esc_html( (string) $summary['manual'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'エラーが発生した項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-error"><?php echo esc_html( (string) $summary['error'] ); ?></dd></div>
			</dl>
		</div>

		<div class="odsc-card">
			<h2><?php esc_html_e( '項目別の取得状況', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( 'WordPressから自動確認できる項目について、取得状況を一覧で確認できます。', 'od-site-check' ); ?></p>
			<ul class="odsc-results-list">
				<?php foreach ( $result['results'] as $item ) : ?>
					<?php
					if ( in_array( $item['id'], ODSC_Collector::manual_ids(), true ) ) {
						continue;
					}

					$status    = $this->get_status_details( $item['status'] );
					$show_note = ( 'WP-03' === $item['id'] || in_array( $item['status'], array( 'partial', 'unavailable', 'unsupported', 'error' ), true ) ) && ! empty( $item['note'] );
					?>
					<li data-odsc-result-id="<?php echo esc_attr( $item['id'] ); ?>">
						<span class="odsc-result-detail">
							<span class="odsc-result-name"><code><?php echo esc_html( $item['id'] ); ?></code> <?php echo esc_html( $labels[ $item['id'] ] ); ?></span>
							<?php if ( $show_note ) : ?>
								<span class="odsc-result-note"><?php echo esc_html( $item['note'] ); ?></span>
							<?php endif; ?>
						</span>
						<span class="odsc-status odsc-status--<?php echo esc_attr( $status['class'] ); ?>" data-odsc-status>
							<span class="dashicons <?php echo esc_attr( $status['icon'] ); ?>" aria-hidden="true"></span>
							<span data-odsc-status-label><?php echo esc_html( $status['label'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="odsc-card">
			<h2><?php esc_html_e( 'ヒアリング前にご確認ください', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( 'ヒアリングでは、以下の情報をお聞きします。可能な範囲で結構ですので、あらかじめご確認をお願いします。', 'od-site-check' ); ?></p>
			<p><?php esc_html_e( '分からない項目があっても問題ありません。ヒアリングの際に内容をご説明しながら確認します。', 'od-site-check' ); ?></p>

			<ul class="odsc-interview-list">
				<?php foreach ( ODSC_Collector::manual_ids() as $id ) : ?>
					<li>
						<h3><code><?php echo esc_html( $id ); ?></code> <?php echo esc_html( $labels[ $id ] ); ?></h3>
						<p><?php echo esc_html( $guidance[ $id ]['description'] ); ?></p>
						<p class="description"><strong><?php esc_html_e( '確認例：', 'od-site-check' ); ?></strong><?php echo esc_html( $guidance[ $id ]['example'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="odsc-card">
			<h2><?php esc_html_e( '診断結果JSON', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( '診断で取得した情報をコピーするか、JSONファイルとしてダウンロードできます。', 'od-site-check' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="odsc-export-form">
				<input type="hidden" name="action" value="odsc_download">
				<input type="hidden" name="odsc_payload" value="<?php echo esc_attr( base64_encode( $json ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes signed JSON for form transport. ?>">
				<input type="hidden" name="odsc_signature" value="<?php echo esc_attr( $exporter->sign( $json ) ); ?>">
				<?php wp_nonce_field( 'odsc_download_result', '_odsc_download_nonce' ); ?>
				<textarea id="odsc-json-preview" class="odsc-json-preview" rows="18" readonly><?php echo esc_textarea( $json ); ?></textarea>
				<div class="odsc-export-actions">
					<button type="button" id="odsc-copy-json" class="button button-secondary"><?php esc_html_e( 'JSONをコピー', 'od-site-check' ); ?></button>
					<?php submit_button( __( 'JSONをダウンロード', 'od-site-check' ), 'primary', 'submit', false ); ?>
				</div>
				<p id="odsc-copy-status" class="odsc-copy-status" role="status" aria-live="polite"></p>
			</form>
		</div>

		<div class="odsc-card">
			<h2><?php esc_html_e( 'ダウンロード後のお願い', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( 'ダウンロードしたJSONファイルをオレインデザインへお送りください。調査終了後は、このプラグインを無効化・削除してください。', 'od-site-check' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Returns translated status labels.
	 *
	 * @return array<string, string>
	 */
	private function get_status_labels() {
		return array(
			'collected'       => __( '取得済み', 'od-site-check' ),
			'partial'         => __( '一部のみ取得', 'od-site-check' ),
			'manual_required' => __( 'ヒアリング確認', 'od-site-check' ),
			'unavailable'     => __( '取得不可', 'od-site-check' ),
			'unsupported'     => __( '未対応', 'od-site-check' ),
			'error'           => __( 'エラー', 'od-site-check' ),
		);
	}

	/**
	 * Returns Dashicons for each status.
	 *
	 * @return array<string, string>
	 */
	private function get_status_icons() {
		return array(
			'collected'       => 'dashicons-yes-alt',
			'partial'         => 'dashicons-warning',
			'manual_required' => 'dashicons-format-chat',
			'unavailable'     => 'dashicons-minus',
			'unsupported'     => 'dashicons-minus',
			'error'           => 'dashicons-dismiss',
		);
	}

	/**
	 * Returns display details for a status.
	 *
	 * @param string $status Status value.
	 * @return array<string, string>
	 */
	private function get_status_details( $status ) {
		$labels = $this->get_status_labels();
		$icons  = $this->get_status_icons();

		if ( ! isset( $labels[ $status ], $icons[ $status ] ) ) {
			$status = 'unavailable';
		}

		return array(
			'class' => $status,
			'label' => $labels[ $status ],
			'icon'  => $icons[ $status ],
		);
	}

	/**
	 * Returns plain-language guidance for interview items.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_interview_guidance() {
		return array(
			'OPS-01' => array(
				'description' => __( '契約しているレンタルサーバー会社やプラン名をご確認ください。', 'od-site-check' ),
				'example'     => __( 'エックスサーバーのスタンダードプラン', 'od-site-check' ),
			),
			'OPS-10' => array(
				'description' => __( 'バックアップに含まれているものをご確認ください。WordPressのファイル、データベース、メールなどが対象です。', 'od-site-check' ),
				'example'     => __( 'WordPressのファイルとデータベース', 'od-site-check' ),
			),
			'OPS-12' => array(
				'description' => __( 'バックアップを取る間隔やタイミング、自動か手動かをご確認ください。', 'od-site-check' ),
				'example'     => __( '毎日1回、自動で取得', 'od-site-check' ),
			),
			'OPS-14' => array(
				'description' => __( '最後にバックアップを取った時期や、正常に完了しているかをご確認ください。', 'od-site-check' ),
				'example'     => __( '2026年9月29日に自動バックアップ成功', 'od-site-check' ),
			),
			'OPS-18' => array(
				'description' => __( 'WordPress本体・テーマ・プラグインを、誰が、どのくらいの頻度で更新しているかをご確認ください。', 'od-site-check' ),
				'example'     => __( '制作会社が月1回、バックアップ後に更新', 'od-site-check' ),
			),
			'OPS-19' => array(
				'description' => __( 'サイトが表示されないなどの問題が起きたとき、誰へ連絡し、どのように対応するかをご確認ください。', 'od-site-check' ),
				'example'     => __( '平日日中は制作会社へ連絡。営業時間外の対応はなし', 'od-site-check' ),
			),
		);
	}

	/**
	 * Gets the request-local exporter instance.
	 *
	 * @return ODSC_Exporter
	 */
	private function get_exporter() {
		if ( null === $this->exporter ) {
			$this->exporter = new ODSC_Exporter();
		}

		return $this->exporter;
	}
}
