# WP-02 本体自動更新設定拡張の検証記録

確認日: 2026-10-24。基準: 0.4.0／Schema 1.3 → 0.5.0／Schema 1.4。hearing 1.0は変更しない。

## 設計と参照範囲

保存値→既知定数→全体停止→フック登録によるunknown、の順に静的な方針を記録する。仕様のJSON例は実測値ではない。保存設定はWordPress APIの参照時既定値を含み、明示的な保存の有無を断定しない。追加の定数値を既存core_auto_update分類に取り込まない。

収集でcore_auto_updates_settings・is_disabled・should_update・更新方針のフィルターは呼ばない。get_site_optionに通常適用されるオプション用フックはAPIの動作として通る。VCS・ファイル変更権限・インストール状態等を網羅する実効検査はせず、effective_behavior_verifiedは常にfalse。

判断表と一次情報は `docs/development/wp02-plan.md`、出力契約とレポート連携は [出力項目一覧](json-output.md) を参照。

## 再現手順

1. `npm run env:start` を実行する。既定ポートが使用中なら `node_modules/.bin/wp-env start --auto-port` を使う。表示された開発用WordPressへadminでログインする。
2. 「ツール → OD サイト診断」で同意して診断する。WP-02の保存設定、定数指定、フィルターの登録有無、最終動作の未検証が分かれていることを確認する。
3. ヒアリングの常時13問を明示回答し、コピーとダウンロードを行う。H-05=yesなら20問を回答する。JSONのschema_version=1.4とWP-02の追加詳細を確認する。
4. 幅375pxの確認は同じサイトの `/wp-content/plugins/od-site-check/tests/browser/narrow.html` を開き、フレーム内で診断する。検証用ページは配布ZIPに含まれない。
5. `composer run lint`、`npm run test:php`、`npm run test:js` を実行する。PHPの対応環境を横断する場合は通常環境を停止して `npm run test:compat` を使う（既定ポートが重なるため）。
6. 配布は `npm run build:zip`、`npm run verify:zip`、`npm run test:smoke`、`node bin/verify-release-version.mjs v0.5.0` で検証する。タグやリリース公開は別操作。

## T01〜T16

| ID | 対象 | 確認方法・結果 |
| --- | --- | --- |
| T01 | WP-02の旧4キーと意味 | 既存DiagnosticTestの定数・キャッシュテストを維持。旧固定fixtureのWP-02から追加詳細だけを除き、旧4キー・source・status・note・error_codeの不変を比較する。 |
| T02 | major/minor=enabled | CoreUpdateSettingsTestで保存値と静的指定を確認。実ブラウザでも両方有効の参照値を表示し、定数未定義と区別した。 |
| T03 | major=unset | 未保存のデフォルトから静的major=unknown。wordpress_defaultを「マイナーのみ」と表示しない。 |
| T04 | WP_AUTO_UPDATE_CORE=false | プロセス分離テストで全種別disabled指定、実行未検証を確認。 |
| T05 | WP_AUTO_UPDATE_CORE=minor | プロセス分離でmajor/dev=disabled、minor=enabled。 |
| T06 | WP_AUTO_UPDATE_CORE=true | プロセス分離で全種別enabled。 |
| T07 | 未知の定数値 | unknown等に加えbetaも既存分類customのまま全種別unknown。現行WordPressの拡張分類を勝手に取り込まない。 |
| T08 | フィルター登録 | 必須5フックを1種類ずつ優先度0で登録。対応種別がunknownとなり、例外を投げるテスト用コールバックが実行されないことを確認。元の登録状態を復元する。 |
| T09 | 全体停止定数 | プロセス分離で停止定数と全種別disabledを確認。auto_update_core登録時は全種別unknownを優先し、最終動作は未検証。 |
| T10 | DISALLOW_FILE_MODS=true | プロセス分離で旧file_modifications_off=true、core_auto_updateとstatusの従来規則、effective_behavior_verified=falseを確認。 |
| T11 | キャッシュ未取得 | キャッシュ削除後にpartial、cached_update_checked=false、日時nullを確認。収集時のDB書込み・外部HTTP・HTML出力も0件。 |
| T12 | マルチサイト | 既存のodsc_is_multisiteフィルターを使う統合テストでWP系unsupported/value=nullと出力を維持。実マルチサイトの管理画面操作は未実施。 |
| T13 | コピー／ダウンロード | 既存PHP実ハンドラ・Nodeテストを維持。実ブラウザで対象全回答後にSchema 1.4のコピー成功・ダウンロード開始と同じJSON文字列を確認。未回答では両ボタンdisabled。 |
| T14 | 他21項目・hearing | 旧0.4.0の13問／20問fixtureを使い、Schema版・collector版とWP-02の追加詳細を明示的に切り分けて、他21項目・WP-02の旧フィールド・hearing全内容を厳密比較。WP-02以外の収集コードと質問定義は変更しない。 |
| T15 | Schema 1.4 | 新しいwp02定義の正常例、必須キー欠落、不正enum・型、最終動作検証trueを拒否。現行13問／20問サンプルを新Schemaで検証。 |
| T16 | 旧1.3 JSON | 旧Schema原本と旧0.4.0サンプルfixtureを保持し、旧Schemaで検証する。レポート側の版分岐・詳細なしの解釈を文書化した。別リポジトリのレポート実装は改修・確認していない。 |

## 実行結果

- PHPCS: 成功。
- Node: 7テスト成功。
- PHPUnit: 最終構成で80テスト・1205 assertions成功。
- `npm run test:compat`: WordPress 7.1／PHP 7.4.33とWordPress 7.1.3／PHP 8.3.36の両環境で各80テスト・1205 assertions成功。
- `node bin/verify-release-version.mjs v0.5.0`: 管理対象のバージョン一致。
- `npm run build:zip` / `npm run verify:zip`: ZIP整合性・許可リスト一致・開発ファイル非混入を確認。
- `npm run test:smoke`: 新規WordPress 7.1／PHP 7.4にZIPを導入・有効化し、新詳細・Schema 1.4・旧Schema 1.3原本の同梱、保存設定と未検証の画面表示、未回答時の両ボタン無効化、13問／20問の統合JSONとダウンロード一致を確認。
- 導入検証後の最終ZIPには検証記録のみを追記して再生成。PHP／JS実行コード・Schema・サンプルは導入検証時と同一。
- 実ブラウザ: 0/13時の両ボタン無効化、13問回答後のコピー／ダウンロード、Schema 1.4・追加詳細・同一文字列を確認。幅375pxで読みやすい配置とアクセシビリティツリーのラベル・値を確認。

## 未実施・制約

実マルチサイト管理画面、スクリーンリーダー実機の音声案内、外部のレポート生成プロジェクトは未検証。更新の実行可否・成功は機能として検証しない。利用者サイトで更新を実行して試すことはしていない。実施済みと未実施を混同せず、レポート側の対応は連携先で別途確認する。
