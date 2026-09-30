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

Docker、Node.js 18.12以上、npm、Composerが必要です。

```sh
npm install
composer install
npm run env:start
```

WordPressは <http://localhost:8888> で起動します。

```sh
composer lint
npm run test:php
npm run build:zip
npm run env:stop
```

JSON Schemaは `schemas/diagnostic-result.schema.json`、配布ZIPは `build/od-site-check-0.1.0.zip` に生成されます。
