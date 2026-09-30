=== OD Site Check ===
Contributors: olein
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPressサイトの構成・運用環境を確認し、診断用JSONを生成します。

== Description ==

OD Site Checkは、オレインデザインによるWordPress運用・改善診断のための情報収集プラグインです。

管理画面の「ツール > OD サイト診断」から、WordPress本体、テーマ、プラグイン、設定、PHP、データベースなどの情報を収集できます。項目別の取得状況と、ヒアリング前に確認していただきたい契約・運用情報を表示します。診断結果はJSONとしてコピーまたは手動でダウンロードします。

サイト設定の変更、更新、修復、バックアップ、診断結果の外部送信は行いません。パスワード、APIキー、認証情報、ユーザーの氏名・メールアドレス・ログインIDは収集しません。

== Installation ==

1. WordPress管理画面から配布ZIPをアップロードします。
2. OD Site Checkを有効化します。
3. 「ツール > OD サイト診断」を開きます。
4. 診断終了後はJSONを保存し、調査完了後にプラグインを無効化・削除します。

== Frequently Asked Questions ==

= 診断結果は自動送信されますか？ =

送信されません。管理者がJSONを手動でダウンロードし、オレインデザインへ提出します。

= サイトの設定やデータは変更されますか？ =

変更されません。初期版の診断処理は読み取り専用です。

= マルチサイトに対応していますか？ =

初期版では対応していません。マルチサイトを検出した場合は、対象項目を未対応として記録します。

== Changelog ==

= 0.1.0 =

* 初期MVPを追加。
