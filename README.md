# OD Site Check

WordPressサイトの構成・運用環境を読み取り、オレインデザインの診断に提出するJSONファイルを生成する非公開プラグインです。

## 主な機能

- WordPress本体、テーマ、プラグイン、ユーザー権限数、一般設定の収集
- PHP、データベース、メモリなどの運用環境情報の収集
- 22項目それぞれの取得状況を、アイコンと状態名で一覧表示
- WordPressから確認できない6つの運用項目を、ヒアリング前の確認事項として案内
- 診断結果JSONのコピーとダウンロード
- サイトのドメインを含むJSONファイル名の生成

診断結果の自動評価、サイト設定の変更、更新、修復、バックアップ、外部送信は行いません。診断結果はサーバーに保存せず、ダウンロード時に権限・Nonce・署名を再確認します。

## 対応環境

- WordPress 7.1以上
- PHP 7.4以上
- 通常の単一サイト

マルチサイトは検出しますが、初期版の診断対象外です。

## 利用方法

1. 配布ZIPをWordPressへインストールして有効化します。
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

JSON Schemaは `schemas/diagnostic-result.schema.json`、配布ZIPは `build/od-site-check-0.1.0.zip` に生成されます。

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
