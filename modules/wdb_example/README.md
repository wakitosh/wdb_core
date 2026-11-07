# wdb_example

WDB Example submodule that creates sample content for trying WDB Core.

## English

### Overview
On first install, this submodule creates the sample "サンプル：保坂本源氏物語": the `genji` subsystem, the source `nich-100412001`, and its pages, annotations, words, and signs. It has no settings page.

### Sample data lifecycle
- **Install:** The sample is created only if neither the `genji` subsystem nor the source `nich-100412001` exists.
- **Reinstall:** If either one exists, nothing is created or changed. Edits to the sample are kept, and data you deleted is not recreated.
- **Uninstall:** All data stays, including anything added later. The module provides no way to delete it. Remove it from the regular admin pages if you no longer need it.

### Upgrading from 1.5.17 or earlier
The mark and purge features were removed. Run `drush updb` and `drush cr`. The update removes the `wdb_example_map` table and the `wdb_example.settings` configuration, and leaves the sample data in place.

---

## 日本語

### 概要
初回インストール時に、サンプル「サンプル：保坂本源氏物語」を作成するサブモジュールです。サブシステム `genji`、資料 `nich-100412001`、およびそのページ、アノテーション、語、サインを作成します。設定画面はありません。

### サンプルデータの扱い
- **インストール時：** サブシステム `genji` と資料 `nich-100412001` のどちらも存在しない場合にのみ、サンプルを作成します。
- **再インストール時：** どちらかが存在する場合は、何も作成・変更しません。サンプルへの編集は保持され、削除したデータも復活しません。
- **アンインストール時：** 後から追加したものを含め、データはすべて残ります。このモジュールに削除機能はありません。不要な場合は、通常の管理画面から削除してください。

### 1.5.17 以前からの更新
マーキングとパージの機能を削除しました。`drush updb` と `drush cr` を実行してください。更新処理は `wdb_example_map` テーブルと `wdb_example.settings` 設定を削除しますが、サンプルデータには触れません。
