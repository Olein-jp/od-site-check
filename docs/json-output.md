# 診断結果JSONの出力項目一覧

対象：OD Site Check 0.4.0 / JSON Schema 1.3 / hearing 1.0。

コピー・ダウンロードされる診断結果JSONについて、項目、取り得る値、意味、取得上の制約をまとめます。型の機械的な定義は [JSON Schema](../schemas/diagnostic-result.schema.json)、取得処理は [収集処理](../includes/class-collector.php) と [各収集クラス](../includes/collectors/) を参照してください。出力仕様を変更したときは、この文書も更新します。

## 値の読み方

- `true` / `false` は真偽値です。意味は各項目で異なります。
- `null` は不明、未取得、または該当なしです。`false` や `0` と同じ意味ではありません。条件は各表に記載します。
- `[]` は一覧の要素が0件です。既知プラグインの検出では、対象一覧に一致するものがないことだけを意味します。
- 文字列の例は固定値ではありません。候補が限定される項目は、候補を列挙しています。名称やバージョンなどは取得元の記載によって空文字列 `""` になる場合もあります。
- 日時はタイムゾーンを含むRFC 3339形式の文字列です。例：`2026-10-24T09:30:00+09:00`。サイト側の日時設定が反映され、常に日本時間とは限りません。
- 取得状態の `collected` は、定義した範囲の情報を取得できたことを示します。サイトの安全性、動作の正常性、情報の最新性の判定ではありません。

## JSON全体の構造

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `schema_version` | 文字列、固定値 `"1.3"` | JSON構造のバージョン。プラグインのバージョンとは別です。 |
| `collector` | オブジェクト | 収集したプラグインの情報。 |
| `collector.name` | 文字列、固定値 `"OD Site Check"` | 収集ツール名。 |
| `collector.version` | 文字列、現行版は `"0.4.0"` | 実際に収集したプラグインのバージョン。 |
| `collected_at` | 日時文字列 | 診断全体の収集日時。更新キャッシュの確認日時とは別です。 |
| `site` | オブジェクト | 対象サイトの情報。 |
| `site.url` | URL文字列 | `home_url('/')` で取得したサイトの公開URL。 |
| `site.multisite` | `true` / `false` | マルチサイトかどうか。`true` の場合、現行版では `WP-` 項目がすべて `unsupported` になります。`OPS-` 項目は収集・手動確認案内を継続します。 |
| `results` | 配列、22件 | 以下の診断項目。後述の一覧順に、各IDが1回ずつ入ります。 |
| `hearing` | オブジェクト、必須 | 次節の自己申告。自動取得の診断結果とは区別します。 |

## 定型ヒアリング `hearing`

| キー | 型・値 | 意味 |
| --- | --- | --- |
| `hearing.schema_version` | 固定値 `"1.0"` | 固定20問の定義バージョン。トップレベルのSchema版とは別です。 |
| `hearing.completed_at` | タイムゾーン付きISO 8601日時 | 全対象質問の回答完了後、出力要求でスナップショットを確定した時刻。診断日時とは別です。 |
| `hearing.answers` | オブジェクト、全20キー必須 | H-01〜H-20。欠落・余分・重複を認めません。 |
| `answers.<ID>.status` | `answered` / `not_applicable` | 現在の対象設問への明示回答か、条件非該当かを示します。 |
| `answers.<ID>.answer` | 許可された回答コード / `null` | `answered` は設問別の許可コード、`not_applicable` は必ず `null`。 |

未回答の内部値は `null` ですが、該当設問が未回答のまま完成JSONを出力することはできません。`unknown` は利用者が明示的に「わからない」を選んだ回答であり、未回答や `no` と同一視しません。`other` は「その他」の選択のみを表し、詳細は取得していません。

常時対象はH-01〜H-05、H-13〜H-20の13問。H-05=`yes` のときだけH-06〜H-12の7問も対象です。H-05=`no` / `unknown` の完成JSONでも、非該当7問を `not_applicable` / `null` として必ず含めます。H-05の回答をyes以外へ変更すると7問の入力を消去し、再びyesにしても復活させません。

[質問一覧](hearing.md)と[同梱の質問定義データ](../schemas/hearing-1.0.json)を参照してください。ラベルは完成JSONへ格納せず、レポート側が同じhearing版の定義を参照して再現します。旧22問版はこの実装に存在せず、版1.0に混在させません。将来、質問文・コード・条件等の意味を変える場合はhearing版を分けます。

完成JSONはPHPの `wp_json_encode()` でUTF-8に整形し、末尾に改行を1つ付けます。同じ回答・診断スナップショットのコピーとダウンロードは同じ文字列・同じ `completed_at` を使い、毎クリックで時刻を再生成しません。変更時は以前のスナップショットを破棄します。

[13問回答のサンプル](samples/hearing-no.json)と[20問回答のサンプル](samples/hearing-yes.json)は例示用データです。どちらにも正規の22診断項目が含まれ、配布Schemaで検証します。ヒアリングは安全性・事実の自動判定ではなく、本人の自己申告として扱ってください。

署名対象の内部診断にはまだhearingがありません。収集テスト用の `#/definitions/diagnosticSnapshot` と、hearing必須の完成出力Schemaを区別します。内部診断だけを旧フォームへ直接POSTしてもダウンロードできません。

## 診断項目に共通するキー

以下の6つのキーは各 `results[]` に必ず出力されます。

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `id` | 文字列 | 診断項目ID。次節の22種類。欠番のIDは出力されません。 |
| `status` | 次表の6種類 | 情報の取得状態。 |
| `source` | 文字列 / `null` | 取得元の識別子。通常時の値は項目一覧を参照。手動確認・未対応・項目単位の例外・サイトヘルス初期化失敗では `null`。 |
| `value` | オブジェクト / `null` | 項目の取得値。内部キーは項目別の表を参照。 |
| `note` | 文字列 / `null` | 人向けの補足や制約。補足がない場合は `null`。翻訳や実装の変更で文言が変わるため、機械判定には使いません。 |
| `error_code` | 文字列 / `null` | 項目単位の取得失敗理由。通常は `null`。エラー一覧は末尾を参照。 |

### `status` の全候補

| 値 | 意味 | `value` / `error_code` |
| --- | --- | --- |
| `collected` | 定義した自動取得範囲の必要値を取得できた。 | オブジェクト / `null` |
| `partial` | 一部取得できたが、取得失敗または自動取得範囲の制約により確認が完結しない。 | オブジェクト / `null`。内部の失敗は専用フィールドへ記録。 |
| `manual_required` | 自動取得せず、人による確認が必要。 | `null` / `null` |
| `unavailable` | 対応機能を初期化できないなどの理由で取得できない。 | `null` / エラーコード |
| `unsupported` | 対応範囲外の環境。現行版ではマルチサイトの `WP-` 項目。 | `null` / `null`。理由は `note`。 |
| `error` | 想定外の項目単位の収集失敗。他項目の収集は継続する。 | `null` / エラーコード |

## 全22項目と取得元

通常時の状態を示します。自動取得項目は、例外が起きると `error` になる場合があります。マルチサイトでは前述の未対応処理が優先されます。

| ID | 内容 | 通常時の `status` と条件 | 通常時の `source` |
| --- | --- | --- | --- |
| WP-01 | WordPressバージョン | `collected` | `wordpress_core` |
| WP-02 | 更新設定 | 定数から設定を特定でき、コア更新キャッシュの確認日時があれば `collected`。それ以外は `partial`。 | `wordpress_configuration` |
| WP-03 | サイトヘルス | 対象11テストをすべて取得できれば `collected`。1件でも実行失敗なら `partial`。初期化失敗は `unavailable`。 | `wordpress_site_health_allowlist` |
| WP-04 | テーマ情報 | 有効な更新キャッシュがあれば `collected`、なければ `partial`。 | `wordpress_theme_api` |
| WP-07 | 有効プラグイン | 有効な更新キャッシュがあれば `collected`、なければ `partial`。 | `wordpress_plugin_api` |
| WP-08 | 無効プラグイン | 有効な更新キャッシュがあれば `collected`、なければ `partial`。 | `wordpress_plugin_api` |
| WP-09 | 独自開発コードの手掛かり | 常に `partial`。コードの内容は調査しない。 | `wordpress_registered_components` |
| WP-10 | 更新上の懸念（保留件数） | コア・プラグイン・テーマの更新キャッシュがすべてオブジェクトとして存在すれば `collected`、それ以外は `partial`。 | `wordpress_update_cache` |
| WP-11 | ユーザー一覧・権限 | `list_users` 権限があれば `collected`、なければ人数集計のみで `partial`。 | `wordpress_user_counts` |
| WP-12 | ログイン保護プラグインの検出 | `collected`。保護設定の確認完了は意味しない。 | `wordpress_plugin_api` |
| WP-13 | 一般設定 | `collected` | `wordpress_options_allowlist` |
| WP-15 | 表示設定 | `collected` | `wordpress_options_allowlist` |
| OPS-01 | サーバー契約 | `manual_required` | `null` |
| OPS-03 | PHP | `collected` | `php_runtime` |
| OPS-04 | データベース | `collected` | `wordpress_database_api` |
| OPS-05 | 容量・リソース | `collected`。サーバー全体の空き容量は対象外。 | `php_runtime` |
| OPS-10 | バックアップ対象 | `manual_required` | `null` |
| OPS-11 | バックアップ方式の手掛かり | 常に `partial`。関連プラグインの存在のみ。 | `wordpress_plugin_api` |
| OPS-12 | バックアップ頻度 | `manual_required` | `null` |
| OPS-14 | バックアップ取得履歴 | `manual_required` | `null` |
| OPS-18 | 更新作業の運用方法 | `manual_required` | `null` |
| OPS-19 | 障害対応体制 | `manual_required` | `null` |

## 項目別の `value`

各表のキーは、対象IDの `value` を起点とします。`[]` は配列内の各要素を示します。`value: null` になる状態では、これらの内部キーは出力されません。

### WP-01：WordPressバージョン

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `version` | 文字列、例 `"7.1"` | インストール済みWordPressのバージョン。 |

### WP-02：更新設定

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `core_auto_update` | `wordpress_default` / `disabled` / `all` / `minor` / `custom` | 下表の定数に基づくコア自動更新設定。 |
| `file_modifications_off` | `true` / `false` | `DISALLOW_FILE_MODS` が定義され、真なら `true`。未定義・偽なら `false`。ファイル権限など、ほかの更新制限は含みません。 |
| `cached_update_checked` | `true` / `false` | コア更新キャッシュに `last_checked` が存在すれば `true`。最新情報の確認済みという意味ではありません。 |
| `cached_update_checked_at` | 日時文字列 / `null` | キャッシュの確認日時。確認日時なし、または値が0の場合は `null`。 |

| `core_auto_update` | 判定条件・意味 |
| --- | --- |
| `wordpress_default` | 判定に使う定数の指定がない。WordPress既定値・管理画面設定・フィルターを含む最終的な自動更新動作は判定不能。 |
| `disabled` | `AUTOMATIC_UPDATER_DISABLED` が真、または `WP_AUTO_UPDATE_CORE` が厳密に `false`。前者を優先。 |
| `all` | `WP_AUTO_UPDATE_CORE` が厳密に `true`。コアの全更新を許可する指定。 |
| `minor` | `WP_AUTO_UPDATE_CORE` が文字列 `"minor"`。マイナー更新を許可する指定。 |
| `custom` | `WP_AUTO_UPDATE_CORE` が上記以外の値。定数から更新範囲を判定不能。 |

管理画面の保存値による補完は行いません。`wordpress_default` と `custom` は必ず `partial` です。定数で設定を取得できた場合も、更新の実行・成功は保証しません。

### WP-03：サイトヘルス

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `environment_type` | 文字列、通常 `local` / `development` / `staging` / `production` | `wp_get_environment_type()` が返す環境種別。 |
| `wp_debug` | `true` / `false` | `WP_DEBUG` が定義され、真か。 |
| `wp_debug_display` | `true` / `false` | `WP_DEBUG_DISPLAY` が定義され、真か。実際のエラー表示を保証する値ではありません。 |
| `wp_debug_log` | `true` / `false` | `WP_DEBUG_LOG` が定義され、真と評価されるか。ログの保存先パスは出力しません。 |
| `tests_attempted` | 整数、固定値 `11` | 実行対象としたテスト数。 |
| `tests_completed` | 整数、0〜11 | 結果を取得できたテスト数。 |
| `status_counts.good` | 0以上の整数 | WordPressが `good` としたテスト数。 |
| `status_counts.recommended` | 0以上の整数 | WordPressが `recommended` としたテスト数。改善を推奨。 |
| `status_counts.critical` | 0以上の整数 | WordPressが `critical` としたテスト数。重大な問題として報告。 |
| `tests` | 配列、0〜11件 | 取得できたテスト結果。 |
| `tests[].id` | 下表の11種類 | テスト識別子。 |
| `tests[].status` | `good` / `recommended` / `critical` | WordPressのテスト評価。項目全体の取得状態 `status` とは別です。 |
| `tests[].label` | 文字列 | WordPressが返す結果のラベル。HTMLタグを除去。環境や翻訳により異なります。 |
| `tests[].category` | 文字列、通常 `パフォーマンス` / `セキュリティ` / `プライバシー` | 下表の分類。翻訳により表記が変わる場合があります。 |
| `execution_failed_tests` | 配列、0〜11件 | 実行できなかったテスト。評価が `critical` だったテストとは異なります。 |
| `execution_failed_tests[].id` | 下表の11種類 | 実行に失敗したテスト識別子。 |
| `execution_failed_tests[].error_code` | 固定値 `test_failed` | 例外・メソッド利用不可・結果形式不正などで結果を取得できなかった。 |

| テストID | 確認対象 | 分類 |
| --- | --- | --- |
| `php_extensions` | PHP拡張機能 | パフォーマンス |
| `php_default_timezone` | PHPの既定タイムゾーン | パフォーマンス |
| `php_sessions` | PHPセッション | パフォーマンス |
| `sql_server` | データベースサーバー | パフォーマンス |
| `ssl_support` | HTTPS関連の対応状態 | セキュリティ |
| `http_requests` | HTTPリクエストの制限設定 | パフォーマンス |
| `debug_enabled` | デバッグ設定 | セキュリティ |
| `file_uploads` | ファイルアップロード設定 | パフォーマンス |
| `insecure_registration` | 安全でないユーザー登録設定 | セキュリティ |
| `search_engine_visibility` | 検索エンジンへの表示設定 | プライバシー |
| `opcode_cache` | Opcodeキャッシュ | パフォーマンス |

サイトヘルスの全テストではありません。外部通信・書き込みを行わない対象11件だけを実行します。`tests_completed` は3種類の評価件数の合計と一致し、実行失敗数との合計は11です。

### WP-04：テーマ情報

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `name` | 文字列 | 使用中テーマ名。 |
| `stylesheet` | 文字列 | 使用中テーマの識別子。通常はテーマディレクトリ名。 |
| `version` | 文字列 | 使用中テーマのバージョン。 |
| `is_child_theme` | `true` / `false` | 親テーマを持つ子テーマなら `true`。 |
| `parent_name` | 文字列 / `null` | 親テーマ名。親テーマなしなら `null`。 |
| `parent_version` | 文字列 / `null` | 親テーマのバージョン。親テーマなしなら `null`。 |
| `update_cache_available` | `true` / `false` | テーマ更新キャッシュがオブジェクトで、数値の `last_checked` を持つか。鮮度は判定しません。 |
| `update_cache_checked_at` | 日時文字列 / `null` | 更新キャッシュの確認日時。利用不可なら `null`。 |
| `update_available` | `true` / `false` / `null` | 使用中テーマにキャッシュ上の更新情報あり／なし／キャッシュ利用不可。子テーマの場合もこの値は子テーマ自身を対象とします。 |
| `new_version` | 文字列 / `null` | 使用中テーマの更新先バージョン。キャッシュ利用不可・更新情報なし・版の記載なしなら `null`。 |
| `installed_themes` | 配列 | インストール済みテーマ一覧。非使用テーマ・親テーマも含みます。 |
| `installed_themes[].name` | 文字列 | テーマ名。 |
| `installed_themes[].stylesheet` | 文字列 | テーマ識別子。 |
| `installed_themes[].version` | 文字列 | インストール済みバージョン。 |
| `installed_themes[].is_active` | `true` / `false` | 現在選択しているテーマなら `true`。子テーマの親は選択中の子テーマと別なので `false`。 |
| `installed_themes[].parent_stylesheet` | 文字列 / `null` | 子テーマが参照する親テーマの識別子。親を参照しないテーマは `null`。 |
| `installed_themes[].auto_update_enabled` | `true` / `false` | 保存済み `auto_update_themes` に含まれるか。 |

自動更新の値は保存された設定です。フィルター等による上書きや実行・成功は保証しません。一覧の各テーマについて更新先情報を出力する構造ではありません。

### WP-07・WP-08：有効・無効プラグイン

通常プラグインの一覧です。MUプラグイン・ドロップインはWP-09を参照します。

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `count` | 0以上の整数 | WP-07は保存された有効プラグイン識別子数、WP-08は検出した無効プラグイン数。WP-07でファイルが失われた識別子が残っている場合、`plugins` の要素数と異なる場合があります。 |
| `update_cache_available` | `true` / `false` | プラグイン更新キャッシュがオブジェクトで、数値の `last_checked` を持つか。 |
| `update_cache_checked_at` | 日時文字列 / `null` | 更新キャッシュの確認日時。利用不可なら `null`。 |
| `plugins` | 配列 | 対象プラグインの一覧。0件なら `[]`。 |
| `plugins[].name` | 文字列 | プラグインヘッダーの名前。 |
| `plugins[].identifier` | 文字列、例 `example/example.php` | プラグインディレクトリからの相対ファイル識別子。サーバーの絶対パスではありません。 |
| `plugins[].version` | 文字列 | インストール済みバージョン。 |
| `plugins[].activation_state` | WP-07は `active`、WP-08は `inactive` | 有効・無効の区別。 |
| `plugins[].auto_update_enabled` | `true` / `false` | 保存済み `auto_update_plugins` に含まれるか。フィルター等による上書きや実行・成功は保証しません。 |
| `plugins[].update_available` | `true` / `false` / `null` | キャッシュ上の更新情報あり／なし／キャッシュ利用不可。 |
| `plugins[].new_version` | 文字列 / `null` | 特定できた更新先バージョン。更新情報なし、キャッシュ利用不可、版の欠落・形式不正なら `null`。 |
| `plugins[].compatibility` | オブジェクト | 現在版と更新先の作者記載情報。次表を参照。 |

#### `plugins[].compatibility`

`installed` は必ずオブジェクトです。`update` はキャッシュから更新先バージョンを特定できた場合だけオブジェクトになり、それ以外は `null` です。両オブジェクトは次のキーを持ちます。

| キー | 型・取り得る値 | `installed` の意味 | `update` の意味 |
| --- | --- | --- | --- |
| `version` | 文字列 | インストール済みバージョン。 | 更新先バージョン。`new_version` と同じ。 |
| `requires_wp` | 数値とドットによる版文字列 / `null`、例 `"6.8"` | ヘッダー `Requires at least` の最低WP要件。 | キャッシュ `requires` の最低WP要件。 |
| `requires_php` | 同上、例 `"7.4"` | ヘッダー `Requires PHP` の最低PHP要件。 | キャッシュ `requires_php` の最低PHP要件。 |
| `tested_wp` | 同上 / `null` | 同梱 `readme.txt` の `Tested up to`。 | キャッシュ `tested` のWP動作確認情報。 |
| `tested_php` | 固定値 `null` | PHPの動作確認上限は標準メタデータから取得不能。 | 同左。 |
| `source` | 固定文字列 | `installed_plugin_headers_and_readme` | `wordpress_update_cache` |
| `checked_at` | 日時文字列 / `null` | 常に `null`。診断時にローカルファイルを読むため、全体の `collected_at` を参照。 | 一覧の `update_cache_checked_at` と同じ確認日時。 |

最低要件・動作確認の版は、欠落・空値・数値とドット以外の形式なら `null` です。更新先の不足情報を現在版で補いません。更新先 `version` は数値とドット、および任意の `-` / `+` で始まる英数字・ドット・ハイフンの接尾辞を許容します（例 `1.2.3-beta.1`）。更新情報があっても版を特定できないときは、`update_available: true` と `new_version: null`、`compatibility.update: null` が同時に出力されます。

同梱readmeは先頭8KBのヘッダーだけを読みます。ファイルがない・読めない場合、プラグインディレクトリ直下の単一ファイルの場合、`Stable tag` が現在版と異なる場合は `installed.tested_wp` が `null` になります。`Stable tag` が未記載または `trunk` の場合は同梱版の作者記載として扱います。

最低要件は対応上限ではありません。`Tested up to` は作者の記載で、動作保証ではありません。その版を超える場合は事前検証が必要です。PHPの対応上限は提供元への確認が必要です。対応情報に `null` があっても、この項目の `status` は `collected` になり得ます。

### WP-09：独自開発コードの手掛かり

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `active_theme_stylesheet` | 文字列 | 使用中テーマ識別子。 |
| `active_theme_is_child` | `true` / `false` | 使用中テーマが子テーマか。 |
| `mu_plugin_count` | 0以上の整数 | WordPress APIで取得したMUプラグイン数。 |
| `mu_plugin_identifiers` | 文字列配列 | MUプラグインのファイル識別子。0件なら `[]`。 |
| `dropin_count` | 0以上の整数 | WordPress APIで取得したドロップイン数。 |
| `dropin_identifiers` | 文字列配列 | ドロップインのファイル識別子。例 `object-cache.php`。 |
| `source_code_scanned` | 固定値 `false` | ソースコードを走査・解析していない。 |

これらの有無だけでは独自開発かどうかを断定できません。通常プラグインやテーマ内の独自コードを網羅する一覧でもありません。

### WP-10：更新上の懸念（保留件数）

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `core_updates_pending` | 0以上の整数 | コア更新キャッシュの `updates` 内で `response: upgrade` の件数。キャッシュなし・該当情報なしでも `0` になるため、0件だけで更新不要と判断しません。 |
| `plugin_updates_pending` | 0以上の整数 / `null` | プラグイン更新キャッシュの `response` 配列の件数。キャッシュまたは配列が取得できない場合は `null`。 |
| `theme_updates_pending` | 0以上の整数 / `null` | テーマ更新キャッシュの `response` 配列の件数。キャッシュまたは配列が取得できない場合は `null`。 |
| `required_update_caches_present` | `true` / `false` | 3種類の更新キャッシュがすべてオブジェクトとして存在するか。確認日時・鮮度・内部情報の完全性までは判定しません。 |

保存済みキャッシュだけに基づき、外部サービスへの照会や更新理由・互換性の判定は行いません。WP-04・WP-07・WP-08のキャッシュ利用可否とは判定条件が異なります。

### WP-11：ユーザー一覧・権限

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `total_users` | 0以上の整数 | ユーザー総数。 |
| `role_counts` | 権限グループ識別子をキー、0以上の整数を値とするオブジェクト | 権限別人数。例：`{"administrator": 1, "editor": 2}`。独自の権限グループも含みます。 |
| `users` | 配列 / `null` | アカウント一覧。実行者に `list_users` 権限がなければ `null`。 |
| `users[].user_login` | 文字列 | ログイン名。 |
| `users[].roles` | 文字列配列 | 所属する権限グループの識別子。複数所属・所属なしの場合もあります。 |
| `users_error_code` | `null` / `list_users_forbidden` | 一覧取得時は `null`。閲覧権限がない場合は `list_users_forbidden`。項目レベルの `error_code` は `null` のままです。 |

標準的な権限グループ識別子は `administrator`（管理者）、`editor`（編集者）、`author`（投稿者）、`contributor`（寄稿者）、`subscriber`（購読者）です。人数集計には `none`（権限グループなし）が含まれる場合があります。複数所属の場合、権限別人数の合計と総数が一致するとは限りません。

ログイン名と権限グループを出力します。氏名・メールアドレス・ユーザーID・パスワードは出力しません。ダウンロード時にも一覧の閲覧権限を再確認します。

### WP-12・OPS-11：既知の関連プラグイン

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `detected_plugins` | 配列 | 次表の既知識別子に一致する、インストール済みプラグイン。無効なものも含みます。 |
| `detected_plugins[].identifier` | 次表の識別子 | 通常はディレクトリ名。WP-07・WP-08の相対ファイル識別子とは異なります。 |
| `detected_plugins[].name` | 次表の名称 | 検出用に定義した名称。プラグインヘッダー名ではありません。 |
| `detected_plugins[].version` | 文字列 | インストール済みバージョン。 |
| `detected_plugins[].active` | `true` / `false` | 保存された有効プラグイン一覧に含まれるか。 |

| 対象ID | `identifier` | `name` |
| --- | --- | --- |
| WP-12 | `wordfence` | Wordfence |
| WP-12 | `better-wp-security` | Solid Security |
| WP-12 | `ithemes-security-pro` | Solid Security Pro |
| WP-12 | `two-factor` | Two Factor |
| WP-12 | `wp-2fa` | WP 2FA |
| WP-12 | `limit-login-attempts-reloaded` | Limit Login Attempts Reloaded |
| WP-12 | `all-in-one-wp-security-and-firewall` | All-In-One Security |
| OPS-11 | `updraftplus` | UpdraftPlus |
| OPS-11 | `backwpup` | BackWPup |
| OPS-11 | `duplicator` | Duplicator |
| OPS-11 | `jetpack` | Jetpack |
| OPS-11 | `all-in-one-wp-migration` | All-in-One WP Migration |
| OPS-11 | `wp-staging` | WP STAGING |
| OPS-11 | `backupbuddy` | Solid Backups |

検出は識別子の一致だけで行います。ログイン保護・MFAの適用、バックアップの設定・対象・実行履歴・復元可否は確認しません。空配列でも、一覧にないプラグインやサーバー側の機能が存在する可能性があります。

### WP-13：一般設定

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `home_url` | URL文字列 | サイトの公開URL。 |
| `site_url` | URL文字列 | WordPress設置URL。公開URLと異なる場合があります。 |
| `timezone_string` | 文字列、例 `Asia/Tokyo` / `""` | 保存されたタイムゾーン名。固定UTCオフセットを使う設定などでは空文字列。 |
| `gmt_offset` | 数値、例 `9`、`5.5` | UTCからの時差（時間）。小数もあり得ます。 |
| `locale` | 文字列、例 `ja`、`en_US` | WordPressのロケール。 |
| `permalink_structure` | 文字列、例 `/%postname%/` / `""` | パーマリンク構造。基本形式では空文字列。 |

### WP-15：表示設定

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `show_on_front` | `posts` / `page` | トップページ表示。`posts` は最新の投稿、`page` は固定ページ。 |
| `front_page_configured` | `true` / `false` | `show_on_front: page` かつ `page_on_front` が正のIDなら `true`。ページの存在・公開状態・表示の正常性は未確認。 |
| `posts_page_configured` | `true` / `false` | `show_on_front: page` かつ `page_for_posts` が正のIDなら `true`。ページの内容は出力しません。 |
| `search_engine_visible` | `true` / `false` | 保存設定 `blog_public` の真偽値。通常 `true` はWordPressのインデックス抑制設定が無効、`false` は抑制設定が有効。 |

`search_engine_visible: true` でも、検索エンジンへの登録・クロール・インデックス・検索順位・SEO上の問題がないことは確認していません。外部の検索エンジンやAPIへの照会は行いません。

### OPS-03：PHP

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `version` | 文字列、例 `8.3.0` | PHPの実行バージョン。 |
| `sapi` | 文字列、例 `fpm-fcgi`、`apache2handler`、`cgi-fcgi`、`cli` | PHPの実行インターフェース。候補をこれらに限定しません。 |

### OPS-04：データベース

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `type` | `MySQL` / `MariaDB` | サーバー情報に `mariadb` を大文字小文字を区別せず含む場合は `MariaDB`、それ以外は `MySQL`。サーバー情報を取得できない場合も後者になります。 |
| `version` | 文字列 | WordPressデータベースAPIの `db_version()` が返すバージョン。 |

データベース名・接続先・ユーザー名・パスワードは出力しません。

### OPS-05：容量・リソース

| キー | 型・取り得る値 | 意味 |
| --- | --- | --- |
| `php_memory_limit` | 文字列、例 `256M`、`-1` | PHPのメモリ上限設定。`-1` はPHP設定上の制限なし。 |
| `wp_memory_limit` | 文字列 / `null` | `WP_MEMORY_LIMIT` の値。定数未定義なら `null`。通常処理向けのメモリ設定。 |
| `wp_max_memory_limit` | 文字列 / `null` | `WP_MAX_MEMORY_LIMIT` の値。定数未定義なら `null`。管理処理等で使うメモリ設定。 |
| `max_execution_time` | 整数、単位は秒 | PHPの最大実行時間設定。`0` はPHP設定上の時間制限なし。 |
| `upload_max_filesize` | 文字列、例 `64M` | PHPのファイル1件当たりのアップロード上限設定。 |
| `post_max_size` | 文字列、例 `128M` | PHPのPOSTデータ全体のサイズ上限設定。 |
| `server_disk_checked` | 固定値 `false` | サーバー全体の空き容量を確認していない。 |

サイズ値はPHP・WordPressから得た表記のままで、バイト数に変換しません。実際の利用可能量やアップロード可否は、サーバー等の別の制限にも影響されます。

### 手動確認の6項目

OPS-01・OPS-10・OPS-12・OPS-14・OPS-18・OPS-19は、`status: manual_required`、`source: null`、`value: null`、`error_code: null` です。`note` にそれぞれサーバー契約内容、バックアップ対象、バックアップ頻度、バックアップ取得履歴、更新作業の運用方法、障害対応体制を別途確認する旨が入ります。自己申告はトップレベルの `hearing` に記録し、この6項目の状態や値を書き換えません。

## エラーコード一覧

| 出力場所 | 値 | 意味 |
| --- | --- | --- |
| 各項目の `error_code` | `null` | 項目単位の失敗なし。`partial` の内部に取得失敗がある場合も、この値は `null`。 |
| 各項目の `error_code` | `collector_failed` | 収集処理の例外、収集処理が見つからない、返却結果の形式不正など。`status: error`。 |
| 各項目の `error_code` | `invalid_status` | 正規化処理に未定義の取得状態が渡された。通常の標準収集処理では発生せず、不正状態の防御処理として `status: error` に変換。 |
| WP-03の `error_code` | `site_health_unavailable` | サイトヘルスクラスを初期化できない。`status: unavailable`。 |
| WP-03の `value.execution_failed_tests[].error_code` | `test_failed` | 個別のサイトヘルステストを取得できない。項目は `partial`。 |
| WP-11の `value.users_error_code` | `list_users_forbidden` | アカウント一覧の閲覧権限なし。人数集計のみで項目は `partial`。 |

JSONファイルの生成・ダウンロード自体が失敗した場合の画面エラーは、この項目別エラーとは別です。

## 取得範囲と実装上の補足

- 更新情報は保存済みキャッシュを参照します。診断時に更新先へ再照会せず、キャッシュの内容が最新公開版とは限りません。`update_available: false` を最新性の保証として扱いません。
- 診断結果の自動評価、設定変更、修復、バックアップ、外部送信は行いません。診断JSONをサーバーに保存する処理もありません。
- サイトURL・ログイン名・構成情報は出力に含まれます。一方、本文、ページタイトル、管理者メールアドレス、秘密情報など、定義した取得対象外の情報は含めません。
- `role_counts` は通常オブジェクトですが、現行実装はPHPの空配列をそのままJSONへ変換するため、権限集計が空の場合は `[]` となる可能性があります。JSON Schemaはオブジェクトを定義しているため、その境界条件では実装と差異があります。
- テスト用・拡張用フィルターによって収集処理が置き換えられた場合、この文書の通常時の条件から外れることがあります。この文書は標準の収集処理を対象とします。
