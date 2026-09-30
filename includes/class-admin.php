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
				'copy'         => __( 'JSONをコピー', 'od-site-check' ),
				'copied'       => __( 'JSONをコピーしました。', 'od-site-check' ),
				'copyFailed'   => __( 'コピーできませんでした。JSON欄を選択してコピーしてください。', 'od-site-check' ),
				'manualNote'   => __( '管理画面で手動入力されました。', 'od-site-check' ),
				'manualFilled' => __( '入力済み', 'od-site-check' ),
				'statusLabels' => $this->get_status_labels(),
				'statusIcons'  => $this->get_status_icons(),
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
		$exporter  = new ODSC_Exporter();

		if ( false === $json || ! $exporter->verify( $json, $signature ) ) {
			wp_die( esc_html__( '診断結果を確認できませんでした。もう一度診断を実行してください。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || ! $exporter->is_valid_payload( $payload ) ) {
			wp_die( esc_html__( '診断結果の形式が正しくありません。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}

		$manual_inputs = array();
		$submitted     = isset( $_POST['odsc_manual'] ) && is_array( $_POST['odsc_manual'] ) ? wp_unslash( $_POST['odsc_manual'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each allowlisted value is sanitized below.

		foreach ( ODSC_Collector::manual_ids() as $id ) {
			if ( isset( $submitted[ $id ] ) && is_string( $submitted[ $id ] ) ) {
				$manual_inputs[ $id ] = wp_html_excerpt( sanitize_textarea_field( $submitted[ $id ] ), 2000, '' );
			}
		}

		$payload = $exporter->apply_manual_inputs( $payload, $manual_inputs );
		if ( ! $exporter->is_valid_payload( $payload ) ) {
			wp_die( esc_html__( '手動入力を反映した診断結果の形式が正しくありません。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}

		$exporter->download( $exporter->encode( $payload ) );
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

		$exporter = new ODSC_Exporter();
		$json     = $exporter->encode( $result );
		$labels   = ODSC_Collector::item_labels();
		?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'サイトの状態確認が完了しました。', 'od-site-check' ); ?></p></div>
		<div class="odsc-card">
			<h2><?php esc_html_e( '診断結果の概要', 'od-site-check' ); ?></h2>
			<dl class="odsc-summary">
				<div><dt><?php esc_html_e( '診断日時', 'od-site-check' ); ?></dt><dd><?php echo esc_html( $result['collected_at'] ); ?></dd></div>
				<div><dt><?php esc_html_e( '取得・入力済み項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-collected"><?php echo esc_html( (string) $summary['collected'] ); ?></dd></div>
				<div><dt><?php esc_html_e( '一部取得・未取得項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-missing"><?php echo esc_html( (string) $summary['missing'] ); ?></dd></div>
				<div><dt><?php esc_html_e( '手動入力待ち項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-manual"><?php echo esc_html( (string) $summary['manual'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'エラーが発生した項目数', 'od-site-check' ); ?></dt><dd id="odsc-summary-error"><?php echo esc_html( (string) $summary['error'] ); ?></dd></div>
			</dl>
		</div>

		<div class="odsc-card">
			<h2><?php esc_html_e( '項目別の取得状況', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( '各項目が取得できたか、追加確認が必要かを一覧で確認できます。', 'od-site-check' ); ?></p>
			<ul class="odsc-results-list">
				<?php foreach ( $result['results'] as $item ) : ?>
					<?php $status = $this->get_status_details( $item['status'] ); ?>
					<li data-odsc-result-id="<?php echo esc_attr( $item['id'] ); ?>">
						<span class="odsc-result-name"><code><?php echo esc_html( $item['id'] ); ?></code> <?php echo esc_html( $labels[ $item['id'] ] ); ?></span>
						<span class="odsc-status odsc-status--<?php echo esc_attr( $status['class'] ); ?>" data-odsc-status>
							<span class="dashicons <?php echo esc_attr( $status['icon'] ); ?>" aria-hidden="true"></span>
							<span data-odsc-status-label><?php echo esc_html( $status['label'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="odsc-card">
			<h2><?php esc_html_e( '手動確認項目を入力', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( 'WordPressから自動取得できない契約・運用情報です。分かる範囲で入力すると、下のJSONへすぐに反映されます。', 'od-site-check' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="odsc-export-form">
				<input type="hidden" name="action" value="odsc_download">
				<input type="hidden" name="odsc_payload" value="<?php echo esc_attr( base64_encode( $json ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes signed JSON for form transport. ?>">
				<input type="hidden" name="odsc_signature" value="<?php echo esc_attr( $exporter->sign( $json ) ); ?>">
				<?php wp_nonce_field( 'odsc_download_result', '_odsc_download_nonce' ); ?>

				<div class="odsc-manual-fields">
					<?php foreach ( ODSC_Collector::manual_ids() as $id ) : ?>
						<label for="odsc-manual-<?php echo esc_attr( strtolower( $id ) ); ?>">
							<span><code><?php echo esc_html( $id ); ?></code> <?php echo esc_html( $labels[ $id ] ); ?></span>
							<textarea id="odsc-manual-<?php echo esc_attr( strtolower( $id ) ); ?>" name="odsc_manual[<?php echo esc_attr( $id ); ?>]" rows="3" maxlength="2000" data-odsc-manual-id="<?php echo esc_attr( $id ); ?>"></textarea>
						</label>
					<?php endforeach; ?>
				</div>

				<h3><?php esc_html_e( 'JSONプレビュー', 'od-site-check' ); ?></h3>
				<p><?php esc_html_e( '入力内容を含むJSONです。コピーするか、ファイルとしてダウンロードできます。', 'od-site-check' ); ?></p>
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
			'partial'         => __( '一部取得', 'od-site-check' ),
			'manual_required' => __( '手動入力待ち', 'od-site-check' ),
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
			'manual_required' => 'dashicons-edit',
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
}
