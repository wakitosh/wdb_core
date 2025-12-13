# Changelog

All notable changes to this module will be documented in this file.

## [1.5.12] - 2025-12-14
### Changed
- TSV import now persists `wdb_annotation_page.image_identifier`: it prefers the TSV value when present, otherwise attempts to generate and save it from the subsystem IIIF identifier pattern. Existing values are never overwritten (mismatches emit a warning).
- Template TSV generation now includes `image_identifier` (placed after `page`) and outputs stored values only, avoiding any dependency on subsystem settings.
- README and the import form help text updated to document the optional `image_identifier` column and its column order.

### 変更 (日本語)
- TSV 取込で `wdb_annotation_page.image_identifier` を永続化するように変更しました。TSV に値があればそれを優先し、無い場合はサブシステムの IIIF Identifier Pattern から生成できる場合に生成して保存します。既存値は上書きせず、不一致は warning として通知します。
- テンプレ TSV 生成に `image_identifier` 列を追加しました（`page` の次）。テンプレ生成は保存済み値のみを出力し、サブシステム設定への依存を避けています。
- README と取込フォームの説明文を更新し、任意列 `image_identifier` と列順を明記しました。

## [1.5.11] - 2025-12-13
### Fixed
- `WdbProtectedDeleteForm` now also sends the “referenced content” error message to Drupal Messenger, guaranteeing that administrators see why a delete action was blocked even when the confirm element is hidden by the theme.

### 修正 (日本語)
- `WdbProtectedDeleteForm` が参照チェックで削除を止めた際に、フォームのエラーに加えて Messenger にも通知するようにし、テーマの構造に関わらず「他コンテンツに参照されているため削除できない」理由が常に表示されるようにしました。

## [1.5.10] - 2025-11-23
### Changed
- IIIF manifests stay publicly reachable, but `IiifV3ManifestController` now withholds `wdb_token` query params unless the current viewer passes the subsystem access policy. Same-domain image servers therefore return 403 for unauthorized viewers even when the manifest is shared, while external (third-party) image hosts keep behaving openly.
- README guidance expanded to clarify how anonymous subsystems, group-restricted subsystems, and cross-domain IIIF servers interact with the token workflow.

### 変更 (日本語)
- IIIF マニフェスト自体は公開のままにしつつ、閲覧者がサブシステムのアクセスポリシーを満たさない場合は `wdb_token` を付与しないよう `IiifV3ManifestController` を調整。同一ドメインの IIIF サーバーでは未認可ユーザーに 403 が返る一方、外部ホストの画像は従来どおり公開状態です。
- README に匿名サブシステム、グループ制限サブシステム、そして別ドメイン IIIF サーバーのトークン連携に関する注意点を追記しました。

### Fixed
- Resolved Drupal cache rebuild failures by injecting the subsystem access policy and current user into `IiifV3ManifestController` and removing the duplicate ControllerBase property declaration.
- Updated `wdb_core.services.yml` so the manifest controller service receives every constructor dependency, preventing container compilation errors during `drush cr`.

### 修正 (日本語)
- `IiifV3ManifestController` で ControllerBase 側のプロパティと重複していた定義を削除し、アクセスポリシー／現在のユーザーサービスを注入することで `drush cr` 時の Fatal エラーを解消しました。
- `wdb_core.services.yml` のサービス定義を更新し、Manifest コントローラーに必要な依存サービスをすべて渡すことでコンテナビルド時の例外を防止しました。

## [1.5.9] - 2025-11-22
### Added
- Configuration form for `wdb_cantaloupe_auth` so token TTL and query parameter can be edited at `/admin/config/wdb/cantaloupe-auth`.
- Token refresh endpoint (`/wdb/api/cantaloupe_auth/token/{page}`) and background refresh logic in the viewer/editor JavaScript to keep long-lived sessions online.
- IIIF identifier patterns now support optional substring filters (e.g., `{source_identifier|substr:0:8}` or `{source_identifier|substr:-4}`) so disparate naming schemes can be generated automatically.

### 追加 (日本語)
- `/admin/config/wdb/cantaloupe-auth` でトークン TTL とクエリパラメータを編集できる設定フォームを追加。
- `/wdb/api/cantaloupe_auth/token/{page}` エンドポイントとビューア／エディタ側の自動更新処理を追加し、長時間開いたままのページでもトークンを維持。
- IIIF Identifier Pattern で `{source_identifier|substr:0:8}` や `{source_identifier|substr:-4}` といった `substr` フィルタを利用できるようになり、識別子の部分文字列を自動生成可能に。

### Changed
- IIIF token validation now falls back to Drupal session cookies when the token expires, preventing editors from being logged out mid-session even with short TTL values.
- README bilingual sections updated to document the new token workflow, refresh behavior, and session fallback expectations.
- Subsystems that allow anonymous access now emit plain IIIF URLs (no `wdb_token` query parameter) so manifests and thumbnails remain compatible with external viewers.

### 変更 (日本語)
- トークン期限切れ時に Drupal セッション Cookie へフォールバックすることで、短い TTL 設定でもログイン中の編集者が 403 にならないよう改善。
- README の日英両セクションで、新しいトークンワークフローと自動更新／フォールバックの説明を追記。
- 匿名アクセスを許可しているサブシステムでは IIIF URL にトークンを付与しないようにし、外部ビューアでもマニフェストやサムネイルをそのまま利用できるようにしました。

## [1.5.7] - 2025-11-21
### Added
- Integration with Drupal Group module for subsystem access control.
- Kernel tests for subsystem access policy (anonymous, permission fallback, group restriction scenarios).
- Group autocomplete UI in subsystem settings (UUID fallback when Group module absent).
- Signed IIIF token workflow documentation (English/Japanese) including reverse proxy header requirements, delegate script and harness usage, and troubleshooting.
- Sample Cantaloupe delegate script and local Ruby harness.
- Update hook that stops upgrades until the Group module is enabled.
- Refresh endpoint and auto-refreshing viewer/editor tokens so extremely short TTL values remain usable for logged-in sessions.
- Configuration form for `wdb_cantaloupe_auth` (token TTL & query parameter) at `/admin/config/wdb/cantaloupe-auth`.

### 追加 (日本語)
- Drupal Group モジュールとの連携によるサブシステムアクセス制御。
- サブシステムアクセスポリシーの Kernel テスト（匿名許可 / 権限フォールバック / グループ制限シナリオ）。
- サブシステム設定フォームに Drupal Group オートコンプリートを追加（Group未導入時はUUID直接入力フォールバック）。
- IIIF サイン付きトークンワークフローの日英ドキュメント（リバースプロキシ必須ヘッダー、delegate スクリプト＆ハーネス利用方法、トラブルシュート）。
- Cantaloupe delegate サンプルスクリプトとローカル Ruby ハーネス。
- Group モジュールが有効になるまでアップデートを停止する update hook を追加。
- ビューア／エディタ用のトークン再発行エンドポイントと自動リフレッシュ処理を追加し、極端に短い TTL でもログイン中のセッションが維持されるよう改善。
- `/admin/config/wdb/cantaloupe-auth` から `wdb_cantaloupe_auth` のトークンTTLとクエリパラメータを変更できる設定フォーム。

### Changed
- `WdbDataService` constructor argument order (optional `$token_manager` moved to the end) to remove PHP 8.4 deprecation warning.
- Updated README bilingual sections for token flow and group restriction instructions.
- IIIF authorization now falls back to Drupal session cookies when a token is missing/expired, keeping logged-in editors online even with aggressive TTLs.

### 変更 (日本語)
- `WdbDataService` コンストラクタ引数順を修正（任意 `$token_manager` を末尾へ移動し PHP 8.4 の警告解消）。
- README のトークンフロー／グループ制限説明を日英両方で更新。
- トークン期限切れでも Drupal セッション Cookie にフォールバックすることで、ログイン中の編集者が短い TTL でも閲覧を継続できるように変更。

### Fixed
- Documentation references to non-existent delegate path replaced with actual script location.
- Minor coding standards issues in newly added kernel test (line lengths, property initialization, dependency injection usage).

### 修正 (日本語)
- 存在しない delegate パス参照を実際のスクリプト配置パスに訂正。
- 新規 Kernel テスト内のコーディング規約上の細かな問題（行長 / プロパティ初期化 / DI）の修正。

### Known Issues / Deprecations
- Group module triggers core annotation-to-attribute deprecation notices (expected until upstream migration to attributes; does not affect functionality).
- Various Drupal 11.x deprecation warnings from Group entity annotations during kernel tests; non-blocking.

### 既知の問題・非推奨 (日本語)
- Group モジュール由来の annotation → attribute 変換予定による非推奨警告（上流移行待ち、機能影響なし）。
- Kernel テスト時に発生する Drupal 11.x の Group エンティティ関連 deprecation は非ブロッキング。

## [1.5.6] - 2025-11-XX
- Previous internal maintenance release (baseline prior to access policy tests and token workflow docs).

---
Semantic Versioning is followed informally. Patch releases include internal refactors or documentation; minor features will bump the minor number when larger API changes occur.# Changelog

All notable changes to WDB Core will be documented in this file.

## [1.5.6] - 2025-11-11

### English
#### Added
- API: New `GET /wdb/api/bbox` endpoint returning a bounding box `{x,y,w,h}` for an arbitrary set of points passed as `points=["x,y", ...]` (JSON array). Complements existing concave hull endpoint for lighter-weight region needs.

#### Changed
- Internal: Refactored export controller to host both hull and bbox calculators for symmetry; docblocks clarified.

### 日本語
#### 追加
- API: 任意の点集合に対しバウンディングボックス `{x,y,w,h}` を返す `GET /wdb/api/bbox` エンドポイントを追加。`points=["x,y", ...]` (JSON配列) を渡す軽量な領域取得手段。既存の凹包APIを補完。

#### 変更
- 内部: Hull と BBox 計算を ExportController 内に整理し、双方の役割を明確化。DocBlock を調整。

## [1.5.5] - 2025-11-05

### English
#### Changed
- Viewer: On URL return, select and center-pan the concave word hull built from full-text word points; removed the character polygon overlay for a cleaner focus.
- Viewer: Kept the safe sequence (Full Text → Annotation Panel → selection & pan) and applied short-lived animation/spring tuning only during hull pan for smoothness.

#### Fixed
- Viewer: Cleared any previous Annotorious selection before drawing the word hull to avoid dual highlights.
- Viewer: Added a fallback to character selection when word points are not available.

### 日本語
#### 変更
- Viewer: 検索からの復帰時、フルテキストの word points から生成した単語の凹包を選択＆中央パンし、文字ポリゴンの重複表示を廃止。
- Viewer: テキスト → パネル → 選択＆パンの順序を維持しつつ、凹包へのパンに限って短時間の一時アニメーション/スプリング調整を適用して滑らかさを確保。

#### 修正
- Viewer: 凹包描画の前に既存の Annotorious 選択をクリアし、二重ハイライトを防止。
- Viewer: word points が取得できない場合は、従来どおり文字ポリゴンへの選択＆パンにフォールバック。

## [1.5.4] - 2025-11-04

### English
#### Changed
- Viewer: Streamlined the URL-return sequence to run Full Text → Annotation Panel → character selection & center pan, eliminating animation hitching.
- Viewer: Improved smoothness by calling `forceRedraw` before pan, kicking off via `requestAnimationFrame`, and applying short-lived animation/spring tuning.

#### Fixed
- Viewer: Strengthened cross-domain label ID matching by normalizing IDs (pathname-only compare, last segment, and `/label/{id}` tail matching).
- Viewer: Removed stray code from the drawer keyboard handler and fixed a syntax error; Space/Enter now reliably toggle the drawer.

### 日本語
#### 変更
- Viewer: URL リターン時のシーケンスを整理し、フルテキスト読込 → アノテーションパネル更新 → 対象文字の選択＆中央パンの順に実行して、アニメーションの引っかかりを解消。
- Viewer: パン開始前の `forceRedraw`、`requestAnimationFrame` 起動、短時間の一時アニメーション/スプリング調整でスムーズさを向上。

#### 修正
- Viewer: cross-domain なラベル ID でも一致しやすいよう ID 正規化（パスのみ比較、末尾セグメント、`/label/{id}` テイル照合）を強化。
- Viewer: Drawer モードのキーボードハンドラに紛れ込んだ不要コードを除去し、構文エラーを修正（Space/Enter でのドロワー開閉を正常化）。

## [1.5.3] - 2025-09-10

### Added
- Annotation panel (split ≥540px): Left Word column now scrolls independently with a sticky header; Right column (Sign + Constituents) scrolls as one, with Sign header sticky.
- Resizers: Touch/pen support via Pointer Events for both horizontal and vertical gutters.

### Changed
- Horizontal and vertical gutters: Enlarged invisible hit areas for easier touch interaction; added active state visual feedback; raised z-index to sit above viewer/panel content.

### Fixed
- iOS Safari: Horizontal gutter drag now works reliably; prevented page scroll/gesture conflicts during resizes using touch-action and overlay suppression.

## [1.5.2] - 2025-09-10

### Changed
- Viewer: Immediate redraw on window/visualViewport resize with a short redraw burst at resize start; lowered redraw throttle to 16ms for snappier updates.
- Layout: Adjust container height immediately on viewport changes (window + VisualViewport), then follow up via requestAnimationFrame.

### Fixed
- Reduced perceived startup lag at the beginning of window resize and removed pauses around layout mode thresholds.
- Smoothed behavior near min/max bounds when dragging, minimizing sticky feel without sacrificing stability.

### Performance
- Live-resize responsiveness: shortened suppression/settle windows and increased resize event cadence during drags (H/V) for more continuous redraw.

## [1.5.0] - 2025-09-09

### Added
- Responsive two-column layout in the right panel when width >= 540px:
	- Left column shows "Word"
	- Right column stacks "Sign" (top) and "Constituent Signs" (bottom)
	- Columns split 50:50 with a continuous center divider
- Toolbar: Add 2px right padding for consistent spacing.

### Changed
- Default layout without saved state: right panel fixed at 270px, viewer fills remaining width.
- Removed Split.js integration and reverted to the legacy resizer implementation.

### Fixed
- Eliminated subtle right panel overflow and transient push-out during fast drags with layout/CSS refinements.

## [1.4.4] - 2025-09-08

### Fixed
- Resizers: Stabilized left/right and top/bottom splitters to prevent snap-back during drag and after release.
- Resizers: Eliminated post-release pixel twitch and enforced explicit widths with per-frame pinning.
- Resizers: Clamped right panel min-width to 270px to avoid layout breakage when dragging to the right.
- Layout: Coordinated vertical resizer with horizontal split using a short-lived cross-axis lock to stop temporary wobble.

### Changed
- Restore logic now prefers saved ratios with min-width aware clamping for consistent behavior across resizes.

## [1.4.0] - 2025-09-06

### Added
- Viewer: Tooltip fade/slide transition (CSS) and class-based visibility control.
- Viewer: Robust hover/tooltip clearing when pointer leaves the viewer or window loses focus.
- Viewer: Initialize hover state on load (`:hover` detection) to ensure immediate hover works after reload.
- Editor: Restored tooltip on annotation hover with the same behavior as viewer, including pointer leave/blur handling.

### Changed
- Viewer: Hover highlight now draws only while the pointer is inside the viewer.

### Fixed
- Viewer: Tooltip and selection no longer remain stuck when quickly exiting the viewer bounds.

[1.4.0]: https://github.com/wakitosh/wdb_module/releases/tag/1.4.0
[1.4.4]: https://github.com/wakitosh/wdb_module/releases/tag/1.4.4
[1.5.0]: https://github.com/wakitosh/wdb_module/releases/tag/1.5.0
[1.5.2]: https://github.com/wakitosh/wdb_module/releases/tag/1.5.2
[1.5.3]: https://github.com/wakitosh/wdb_module/releases/tag/1.5.3
[1.5.4]: https://github.com/wakitosh/wdb_module/releases/tag/1.5.4
