# WP-02 本体自動更新設定拡張の設計（#25）

2026-10-24。基準: 67aced9、OD Site Check 0.4.0／Schema 1.3。利用者の「24から順に開発し、Issueごとにコミット・クローズする」という指示に基づき、以下を採用して継続する。

1. **読み取る値と正規化**: 既存4キーのロジックは変更しない。追加する `core_auto_update_details.saved_options` は `get_site_option()` で取得し、majorの参照時既定値は `unset`、minor/devは `enabled`。文字列 `enabled`／`disabled`／`unset` 以外（型違いを含む）は `custom` に正規化し、生値は出力しない。APIはオプション用フィルターを通るため、生DBの明示保存の有無を保証する補助フラグは追加しない。画面でも「参照時の既定値を含む」と説明する。option_sourceは `wordpress_site_options`。constant_policyは既存core_auto_updateと同じ値、global_disable_constantは定数の真偽。
2. **静的な判断表**: 下表の順で解釈する。保存値による解釈に、既知の定数指定を優先する。定数未定義でmajor=unsetならunknownのままにする。WP版から設置時期を推測しない。未知の定数値は、公式の新しい実装で意味を持つ文字列であっても、既存分類がcustomである限り全種別unknownとする。DISALLOW_FILE_MODSは既存file_modifications_offにのみ記録し、静的なdeclared_policyから最終動作を断定しない。
3. **フックの扱い**: `has_filter()` で必須5フックの登録有無だけを見る。automatic_updater_disabled／auto_update_coreは全種別をunknown、allow_major/minor/dev_auto_core_updatesは対応種別をunknownにする。全体停止定数が真の場合も、保守的に同じunknown規則を最後に適用する。WordPress自身の登録も除外せず、優先度0も検出する。コールバック、core_auto_updates_settings、is_disabled、should_updateは呼ばない。effective_behavior_verifiedは常にfalse、limitationは固定の収集制約を記録する。
4. **出力と版管理**: Schemaを1.4、hearingは1.0のままにする。追加オブジェクトをWP-02のcollected／partial時に必須とし、unsupported等のvalue=nullは維持。1.3のSchema原本を `schemas/diagnostic-result-1.3.schema.json` として残す。旧1.3の13問／20問サンプルもfixtureへ保持し、旧Schemaで検証する。collectorは#28で0.5.0へ揃える。旧版データを自動変換せず、レポート側の1.3／1.4の分岐を文書化する。別リポジトリの対応は未確認と記録する。
5. **実装順序と検証**: #26で収集・Schema・判断表テスト、#27で画面・表示テスト、#28でT01〜T16の統合回帰・文書・サンプル・版管理・ZIPを整備する。入口はclass-wordpress.php、class-admin.php、Schema、既存Diagnostic/Hearing/ExportTest。定数は既存のプロセス分離を使い、フックは元の登録を保存・復元する。旧4キーと他21項目・hearingの不変、DB書込み／外部通信なし、署名・権限・nonce・全回答条件を確認し、PHPCS／PHPUnit／Node／PHP7.4・8.3互換性／ZIP新規導入を実行する。タグ・push・公開は行わない。

## 静的解釈の判断表（major／minor／dev）

| 優先順 | 入力 | declared_policy |
| --- | --- | --- |
| 1 | 定数未定義、保存値enabled/disabled | 対応値を採用 |
| 1 | 定数未定義、保存値unset/custom | 対応値はunknown |
| 2 | WP_AUTO_UPDATE_CORE=true | enabled / enabled / enabled |
| 2 | WP_AUTO_UPDATE_CORE=false | disabled / disabled / disabled |
| 2 | WP_AUTO_UPDATE_CORE=minor | disabled / enabled / disabled |
| 2 | WP_AUTO_UPDATE_COREに未知値 | unknown / unknown / unknown |
| 3 | AUTOMATIC_UPDATER_DISABLEDが真 | disabled / disabled / disabled |
| 4 | 全体に影響する必須フックが登録 | unknown / unknown / unknown |
| 4 | 種別の必須フックが登録 | 対応する種別だけunknown |

保存値は定数で書き換えず、別々に記録する。定数が既知なら保存major=unsetでも定数による静的指定を記録できるが、実際の更新可否・成功の確認ではない。既存statusはキャッシュと既存定数分類だけで決まり、保存値取得で改善しない。

## 一次情報（2026-10-24確認）

- [core_auto_updates_settings](https://developer.wordpress.org/reference/functions/core_auto_updates_settings/): 参照時デフォルトと定数による設定の解釈。HTML表示関数なので収集には呼ばない。
- [Core_Upgrader::should_update_to_version](https://developer.wordpress.org/reference/classes/core_upgrader/should_update_to_version/): 種別のフィルターを評価する実処理。今回その評価は行わない。
- [WP_Automatic_Updater::is_disabled](https://developer.wordpress.org/reference/classes/wp_automatic_updater/is_disabled/): ファイル変更制限・インストール状態・全体停止フック等の動作条件。網羅的な検査は対象外。

WordPressが扱う定数の範囲を今回の既存core_auto_update分類へ取り込む拡張は行わず、仕様の保守的なunknown規則を優先する。
