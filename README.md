# OD Site Check

WordPressサイトの構成・運用環境を読み取り、オレインデザインの診断に提出するJSONファイルを生成する非公開プラグインです。

## 主な機能

- WordPress本体、テーマ、プラグイン、ユーザー権限数・ログイン名と権限グループ、一般設定の収集
- PHP、データベース、メモリなどの運用環境情報の収集
- 22項目それぞれの取得状況を、アイコンと状態名で一覧表示
- WordPressから確認できない6つの運用項目を、ヒアリング前の確認事項として案内
- 診断結果JSONのコピーとダウンロード
- サイトのドメインを含むJSONファイル名の生成

診断結果の自動評価、サイト設定の変更、更新、修復、バックアップ、外部送信は行いません。診断結果はサーバーに保存せず、ダウンロード時に権限・Nonce・署名を再確認します。

## 診断範囲

`WP-15`の`search_engine_visible`は、WordPressの「検索エンジンがサイトをインデックスしないようにする」設定（`blog_public`）だけを表します。`true`でも、検索エンジンへの登録、クロール、インデックス済みかどうか、検索順位、SEO上の問題がないことは確認していません。この値をSEOの正常判定には使用しません。外部の検索エンジンやAPIへの照会も行いません。

### プラグインの対応情報（JSON Schema 1.1）

`WP-07`（有効）と `WP-08`（無効）の各プラグインに `compatibility` を追加します。既存の名前・識別子・バージョン・更新情報は維持します。構造変更に伴い `schema_version` は `1.2`、Schema の ID は `diagnostic-result-1.2.json` です。厳密な Schema 検証を行う利用側も更新してください。

- `installed`: インストール済み版の情報。`version` は現在版、`requires_wp` / `requires_php` はプラグインヘッダーの最低要件、`tested_wp` は同梱 `readme.txt` の `Tested up to` です。
- `update`: 保存済み更新キャッシュに記録された更新先の情報。`version` は更新先、`requires_wp` / `requires_php` / `tested_wp` はそれぞれキャッシュの `requires` / `requires_php` / `tested` です。現在版の値で欠落を補いません。
- `source`: 現在版は `installed_plugin_headers_and_readme`、更新先は `wordpress_update_cache`。現在版の最低要件と動作確認情報の取得元は上記のとおりです。
- `checked_at`: 更新先はキャッシュの確認日時（一覧の `update_cache_checked_at` と同じ）。現在版はローカルファイルを診断時に読むため `null` で、診断全体の `collected_at` を参照します。
- `tested_php`: 両方とも `null`。PHP の動作確認上限は標準メタデータから取得できず、提供元への確認が必要です。

要件・動作確認情報の欠落、空値、数値のバージョン形式でない値は `null` とします。同梱 readme がない・読めない場合、プラグインがプラグインディレクトリ直下の単一ファイルの場合、readme の `Stable tag` が現在版と異なる場合は `tested_wp` を不明にします（`trunk` または未記載は同梱版の作者の記載として扱います）。readme は先頭8KBのヘッダーだけを取得します。

更新キャッシュなし、更新なし、更新先バージョンが欠落・不正な場合は `update` が `null` です。更新有無は既存の `update_available` で区別します。更新先バージョンは数値とドットの組み合わせ、および任意の `-` / `+` に続く英数字等の接尾辞を対象とし、それ以外は `new_version` も `null` とします。これらの不明値を「対応済み」「安全」「最新」と解釈しないでください。診断時の外部照会は行わず、キャッシュの更新先が最新公開版とは限りません。

最低要件は対応上限ではありません。`Tested up to` は作者が記載した WP の動作確認済みバージョンであり、動作保証ではありません。そのバージョンを超える場合は不具合が確定するわけではありませんが、アップデート前の検証が必要です。最低 PHP 要件から PHP の対応上限を推測しません。`collected` は定義した取得範囲の処理完了を示し、すべてのプラグインで対応情報が揃ったことや安全性を意味しません。

### 取得状態の仕様

各診断項目の`status`は、サイトの良否や安全性ではなく、情報をどこまで取得できたかを表します。

- `collected`: その項目で定義した自動取得範囲の必要値を取得できた
- `partial`: 一部の値は取得できたが、取得失敗または自動取得範囲の制約により、その値だけでは確認が完結しない
- `manual_required`: プラグインでは自動取得せず、人による確認が必要
- `unavailable`: 対応機能はあるが、必要なAPIやクラスを初期化できないなどの理由で取得できない
- `unsupported`: 現在のプラグインが診断対象として対応していない環境
- `error`: 想定外の項目単位の収集失敗。ほかの項目の収集は継続する

`error`と`unavailable`では`value`が`null`となり、機械判定用の安定した`error_code`が入ります。`collected`、`partial`、`manual_required`の項目レベルの`error_code`は`null`です。`partial`の内部に個別の失敗がある場合は、`value`内の専用フィールドに記録されます。`unsupported`では`value`と`error_code`が`null`となり、理由を`note`で説明します。

## 対応環境

- WordPress 7.1以上
- PHP 7.4以上
- 通常の単一サイト

マルチサイトは検出しますが、初期版の診断対象外です。

## 利用方法

1. [最新リリース](https://github.com/Olein-jp/od-site-check/releases/latest) の Assets から `od-site-check-[バージョン].zip` をダウンロードし、WordPressへアップロードして有効化します。GitHub が自動表示する Source code の ZIP は使用しません。
2. 「ツール > OD サイト診断」を開きます。
3. 収集内容を確認して「サイトの状態を確認する」を押します。
4. 項目別の取得状況と、ヒアリング前の確認事項を確認します。
5. JSONをコピーするか、`od-site-check-[ドメイン]-[日時].json`として保存します。
6. JSONをオレインデザインへ送付します。
7. 調査終了後、プラグインを無効化・削除します。

## 開発環境

以下が必要です。

- Docker
- Node.js 18.12以上とnpm
- PHP 7.4以上とComposer
- ZIPを扱うための`zip`と`unzip`

```sh
npm install
composer install
npm run env:start
```

WordPressは <http://localhost:8888> で起動します。

```sh
composer run lint
npm run test:php
npm run build:zip
npm run verify:zip
npm run env:stop
```

JSON Schemaは `schemas/diagnostic-result.schema.json`、配布ZIPは `build/od-site-check-0.3.0.zip` に生成されます。

### 対応環境の検証

プラグインヘッダーで宣言している最小環境と、代表的な現行環境の両方でPHPUnitを実行できます。

- WordPress 7.1 / PHP 7.4（最小対応環境）
- 現行安定版WordPress / PHP 8.3（代表環境）

```sh
npm run test:compat
```

完成した配布ZIPについては、開発用ファイルや依存関係を含まないことを確認したうえで、新規のWordPress 7.1 / PHP 7.4環境へインストールします。有効化後に管理画面の診断処理とJSON表示までを自動確認します。

```sh
npm run test:smoke
```

互換性テストとZIP導入テストは専用のDocker環境を使用するため、初回はWordPressやPHPイメージの取得に時間がかかることがあります。ZIP導入テストの専用環境は、テスト終了時に破棄されます。

配布対象は `bin/package-files.txt` の許可リストで管理しています。`npm run verify:zip` はZIPの破損に加え、テスト、開発用コマンド、`vendor`、`node_modules`、`.env`、Git管理情報が含まれていないことも検査します。

## バージョン別の配布

[GitHub Releases](https://github.com/Olein-jp/od-site-check/releases) に、各バージョンのインストール用 ZIP と SHA-256 チェックサムを保存します。過去のリリースは残し、通常は [最新リリース](https://github.com/Olein-jp/od-site-check/releases/latest) からダウンロードしてください。

開発者は変更を main に取り込んだ後、プラグインヘッダー・`ODSC_VERSION`・readme の Stable tag・package.json・package-lock.json のバージョンを揃えたコミットに `vX.Y.Z` タグを付け、origin に push します。GitHub Actions がバージョン一致、ZIP の整合性、配布ファイルの許可リストを検証した後にリリースを公開します。既存のタグ・リリースは上書きせず、修正時は新しいバージョンを公開してください。公開前の静的チェックと WordPress 上の動作確認は開発者が実行します。

```sh
node bin/verify-release-version.mjs v0.3.0
git tag v0.3.0
git push origin v0.3.0
```

### 自動更新設定とアカウント一覧（JSON Schema 1.2）

`WP-02` は定数のみでコアの自動更新を判定します。`wordpress_default`（定数未定義）・`custom` は判定不能で、取得状態は `partial` です。管理画面の保存値で補完しません。

`WP-07`・`WP-08` の `auto_update_enabled` と `WP-04` の `installed_themes` 内の同名フィールドは、保存された自動更新設定です。テーマ一覧には非使用テーマ・親テーマも含み、`parent_stylesheet` で親を識別します。フィルターによる上書きや更新の実行・成功は保証しません。MUプラグイン・ドロップインはこの判定の対象外です。

`WP-11` は人数集計に加え、`list_users` 権限がある場合に `users`（`user_login` と `roles` のみ）を出力します。権限がない場合は `users: null`、`users_error_code: list_users_forbidden`、取得状態は `partial` です。ダウンロード時にも一覧の閲覧権限を再確認します。
