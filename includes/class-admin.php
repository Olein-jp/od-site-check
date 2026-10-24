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
		add_action( 'admin_post_odsc_prepare', array( $this, 'handle_prepare' ) );
	}

	/**
	 * Registers the Tools submenu.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		$hook = add_management_page(
			__( 'OD Site Check', 'od-site-check' ),
			__( 'OD Site Check', 'od-site-check' ),
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
		wp_enqueue_script( 'odsc-hearing', ODSC_PLUGIN_URL . 'assets/hearing.js', array(), ODSC_VERSION, true );
		wp_enqueue_script( 'odsc-admin', ODSC_PLUGIN_URL . 'assets/admin.js', array( 'odsc-hearing' ), ODSC_VERSION, true );
		wp_enqueue_script( 'odsc-export', ODSC_PLUGIN_URL . 'assets/export.js', array( 'odsc-admin' ), ODSC_VERSION, true );
		wp_localize_script(
			'odsc-admin',
			'odscAdmin',
			array(
				'hearing'      => ODSC_Hearing::definition(),
				'checking'     => __( '確認しています…', 'od-site-check' ),
				'checkingNote' => __( 'サイトの情報を確認しています。この画面を閉じずにお待ちください。', 'od-site-check' ),
				'copied'       => __( 'JSONをコピーしました。', 'od-site-check' ),
				'copyFailed'   => __( 'コピーできませんでした。回答と権限を確認し、再度お試しください。', 'od-site-check' ),
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

		$this->result = null;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この診断を実行する権限がありません。', 'od-site-check' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'odsc_run_diagnostic', '_odsc_nonce' );

		$consent = isset( $_POST['odsc_consent'] ) ? sanitize_key( wp_unslash( $_POST['odsc_consent'] ) ) : '';
		if ( '1' !== $consent ) {
			$this->error = __( '収集内容をご確認のうえ、チェックボックスを選択してください。', 'od-site-check' );
			return;
		}

		try {
			$this->result = ( new ODSC_Collector() )->collect();
		} catch ( Throwable $exception ) {
			$this->error = __( '診断に失敗しました。もう一度実行してください。', 'od-site-check' );
		}
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

		$source = $this->read_export_request( true );
		$this->get_exporter()->download( $source['json'] );
	}

	/**
	 * 明示的な出力要求だけを受け、完成文字列を返す。
	 *
	 * @return void
	 */
	public function handle_prepare() {
		$source    = $this->read_export_request( false );
		$rows_json = isset( $_POST['odsc_answers'] ) && is_string( $_POST['odsc_answers'] ) ? wp_unslash( $_POST['odsc_answers'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- read_export_requestでnonce検証済み。配列形と固定コードを下で厳密に検証する。
		$rows      = json_decode( $rows_json );
		$answers   = ODSC_Hearing::answers_from_rows( $rows );
		if ( false === $answers ) {
			wp_die( esc_html__( 'ヒアリングの回答が完了していないか、形式が正しくありません。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}
		$payload  = $source['payload'];
		$exporter = $this->get_exporter();
		if ( array_key_exists( 'hearing', $payload ) ) {
			if ( ! $exporter->is_valid_payload( $payload ) || $answers !== $payload['hearing']['answers'] ) {
				wp_die( esc_html__( '回答と出力内容が一致しません。', 'od-site-check' ), '', array( 'response' => 400 ) );
			}
			$json = $source['json'];
		} else {
			$payload['hearing'] = ODSC_Hearing::create( $answers );
			$json               = $exporter->encode( $payload );
		}
		$exporter->respond(
			array(
				'json'      => $json,
				'signature' => $exporter->sign( $json ),
				'filename'  => $exporter->get_filename(),
			)
		);
	}

	/**
	 * 準備・ダウンロードに共通のサーバー側防御。
	 *
	 * @param bool $complete 完成JSONを要求するか.
	 * @return array
	 */
	private function read_export_request( $complete ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '診断結果を出力する権限がありません。', 'od-site-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $complete ? 'odsc_download_result' : 'odsc_prepare_result', $complete ? '_odsc_download_nonce' : '_odsc_prepare_nonce' );
		$encoded   = isset( $_POST['odsc_payload'] ) && is_string( $_POST['odsc_payload'] ) ? sanitize_text_field( wp_unslash( $_POST['odsc_payload'] ) ) : '';
		$signature = isset( $_POST['odsc_signature'] ) && is_string( $_POST['odsc_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['odsc_signature'] ) ) : '';
		$json      = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 署名済みJSONの転送形式。
		$exporter  = $this->get_exporter();
		if ( false === $json || ! $exporter->verify( $json, $signature ) ) {
			wp_die( esc_html__( '診断結果を確認できませんでした。もう一度診断を実行してください。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || ! $exporter->is_valid_diagnostic( $payload ) || ( $complete && ! $exporter->is_valid_payload( $payload ) ) ) {
			wp_die( esc_html__( '診断結果の形式が正しくありません。', 'od-site-check' ), '', array( 'response' => 400 ) );
		}
		foreach ( $payload['results'] as $result ) {
			if ( 'WP-11' === $result['id'] && isset( $result['value']['users'] ) && ! current_user_can( 'list_users' ) ) {
				wp_die( esc_html__( 'アカウント一覧を出力する権限がありません。', 'od-site-check' ), '', array( 'response' => 403 ) );
			}
		}
		return array(
			'json'    => $json,
			'payload' => $payload,
		);
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
			<h1><?php esc_html_e( 'OD Site Check', 'od-site-check' ); ?></h1>

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
			<p><?php esc_html_e( 'WordPressのバージョン、テーマ、プラグイン、設定、PHPやデータベースなど、サイト改善の検討に必要な情報を収集します。ユーザー一覧の閲覧権限がある場合は、アカウント名（ログイン名）と権限グループも含みます。', 'od-site-check' ); ?></p>
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
						<div class="odsc-result-detail">
							<span class="odsc-result-name"><code><?php echo esc_html( $item['id'] ); ?></code> <?php echo esc_html( $labels[ $item['id'] ] ); ?></span>
							<?php if ( $show_note ) : ?>
								<span class="odsc-result-note"><?php echo esc_html( $item['note'] ); ?></span>
							<?php endif; ?>
							<?php
							if ( 'WP-02' === $item['id'] ) {
								$this->render_core_update_settings( $item['value'] ); }
							?>
						</div>
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

		<?php $this->render_hearing(); ?>
		<div class="odsc-card">
			<h2><?php esc_html_e( '診断結果JSON', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( '対象のヒアリングにすべて回答すると、診断と自己申告をまとめたJSONを出力できます。', 'od-site-check' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="odsc-export-form" data-diagnostic-valid="<?php echo $exporter->is_valid_diagnostic( $result ) ? '1' : '0'; ?>">
				<input type="hidden" name="action" value="odsc_download">
				<input type="hidden" name="odsc_payload" value="<?php echo esc_attr( base64_encode( $json ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes signed JSON for form transport. ?>">
				<input type="hidden" name="odsc_signature" value="<?php echo esc_attr( $exporter->sign( $json ) ); ?>">
				<?php wp_nonce_field( 'odsc_download_result', '_odsc_download_nonce' ); ?>
				<?php wp_nonce_field( 'odsc_prepare_result', '_odsc_prepare_nonce' ); ?>
				<textarea id="odsc-json-preview" class="odsc-json-preview" rows="18" readonly aria-label="<?php esc_attr_e( '出力用JSON', 'od-site-check' ); ?>"></textarea>
				<div class="odsc-export-actions">
					<button type="button" id="odsc-copy-json" class="button button-secondary" disabled><?php esc_html_e( 'JSONをコピー', 'od-site-check' ); ?></button>
					<button type="submit" id="odsc-download-json" class="button button-primary" disabled><?php esc_html_e( 'JSONをダウンロード', 'od-site-check' ); ?></button>
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
	 * 本体自動更新の参照設定と最終動作未検証を分けて表示する。
	 *
	 * @param mixed $value WP-02の取得値.
	 * @return void
	 */
	private function render_core_update_settings( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['core_auto_update_details'] ) || ! is_array( $value['core_auto_update_details'] ) ) {
			return;
		}
		$details   = $value['core_auto_update_details'];
		$labels    = array(
			'enabled'  => __( '有効', 'od-site-check' ),
			'disabled' => __( '無効', 'od-site-check' ),
			'unset'    => __( '未指定', 'od-site-check' ),
			'custom'   => __( '独自の値のため不明', 'od-site-check' ),
		);
		$constants = array(
			'wordpress_default' => __( '定数から更新範囲を判定できません', 'od-site-check' ),
			'all'               => __( '全更新を許可する指定', 'od-site-check' ),
			'minor'             => __( 'マイナー更新のみを許可する指定', 'od-site-check' ),
			'disabled'          => __( '自動更新を停止する指定', 'od-site-check' ),
			'custom'            => __( '独自の定数値のため不明', 'od-site-check' ),
		);
		$fields    = array(
			'major' => __( 'メジャー更新の保存設定', 'od-site-check' ),
			'minor' => __( 'マイナー更新の保存設定', 'od-site-check' ),
			'dev'   => __( '開発版更新の保存設定', 'od-site-check' ),
		);
		?>
		<section class="odsc-core-update-settings" aria-labelledby="odsc-core-update-heading">
			<h3 id="odsc-core-update-heading"><?php esc_html_e( 'WordPress本体の自動更新設定', 'od-site-check' ); ?></h3>
			<dl>
				<?php foreach ( $fields as $type => $label ) : ?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd><?php echo esc_html( $labels[ $details['saved_options'][ $type ] ?? '' ] ?? __( '不明', 'od-site-check' ) ); ?></dd>
				<?php endforeach; ?>
				<dt><?php esc_html_e( '定数による自動更新の指定', 'od-site-check' ); ?></dt>
				<dd><?php echo esc_html( $constants[ $details['constant_policy'] ?? '' ] ?? __( '不明', 'od-site-check' ) ); ?></dd>
				<dt><?php esc_html_e( '全体停止定数による指定', 'od-site-check' ); ?></dt>
				<dd><?php echo esc_html( ! empty( $details['global_disable_constant'] ) ? __( '停止指定あり', 'od-site-check' ) : __( '停止指定なし', 'od-site-check' ) ); ?></dd>
				<dt><?php esc_html_e( '更新方針に関わるフィルターの登録', 'od-site-check' ); ?></dt>
				<dd><?php echo esc_html( in_array( true, $details['registered_filters'] ?? array(), true ) ? __( 'あり（効果は未検証）', 'od-site-check' ) : __( '対象5種類では検出なし', 'od-site-check' ) ); ?></dd>
				<dt><?php esc_html_e( '自動更新の最終動作', 'od-site-check' ); ?></dt>
				<dd><?php esc_html_e( '未検証', 'od-site-check' ); ?></dd>
			</dl>
			<p class="description"><?php esc_html_e( '保存設定はWordPress APIの参照値で、未保存時の既定値を含みます。明示的な保存の有無は区別していません。保存設定と定数は別の情報です。登録されたフィルターで変わる可能性もあるため、更新の実行可否や成功は保証しません。', 'od-site-check' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 固定定義からアクセシブルな回答欄を描画する。
	 *
	 * @return void
	 */
	private function render_hearing() {
		$definition = ODSC_Hearing::definition();
		?>
		<div class="odsc-card" id="odsc-hearing">
			<h2><?php esc_html_e( '定型ヒアリング', 'od-site-check' ); ?></h2>
			<p><?php esc_html_e( 'ヒアリングは自己申告です', 'od-site-check' ); ?></p>
			<p><?php esc_html_e( '回答は保存されません。再読み込みで消去されます', 'od-site-check' ); ?></p>
			<p><?php esc_html_e( '出力するJSONにはアカウントのログイン名とサイト構成情報を含みます。送付先と保管方法にご注意ください。', 'od-site-check' ); ?></p>
			<p id="odsc-hearing-progress" role="status" aria-live="polite"></p>
			<div id="odsc-hearing-missing"></div>
			<?php if ( ODSC_Hearing::has_definitions() ) : ?>
				<?php foreach ( $definition['groups'] as $group ) : ?>
					<section data-hearing-group="<?php echo esc_attr( $group['id'] ); ?>" <?php echo in_array( $group['id'], array( 'C', 'D' ), true ) ? 'hidden' : ''; ?>>
						<h3><?php echo esc_html( $group['label'] ); ?></h3>
						<?php foreach ( $definition['questions'] as $question ) : ?>
							<?php
							if ( $question['group'] !== $group['id'] ) {
								continue; }
							?>
							<fieldset id="odsc-<?php echo esc_attr( $question['id'] ); ?>" tabindex="-1" class="odsc-question" <?php echo null !== $question['condition'] ? 'hidden disabled' : ''; ?>>
								<legend><strong><?php echo esc_html( $question['id'] . ' ' . $question['question'] ); ?></strong></legend>
								<?php foreach ( $question['options'] as $code => $label ) : ?>
									<label class="odsc-answer"><input type="radio" name="hearing[<?php echo esc_attr( $question['id'] ); ?>]" value="<?php echo esc_attr( $code ); ?>" autocomplete="off"> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						<?php endforeach; ?>
					</section>
				<?php endforeach; ?>
			<?php else : ?>
				<p role="alert"><?php esc_html_e( '質問定義を読み込めません。JSONは出力できません。', 'od-site-check' ); ?></p>
			<?php endif; ?>
			<p><a id="odsc-restart" href="<?php echo esc_url( admin_url( 'tools.php?page=od-site-check' ) ); ?>"><?php esc_html_e( '回答を破棄して診断をやり直す', 'od-site-check' ); ?></a></p>
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
