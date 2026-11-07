# Changelog

All notable changes to this module will be documented in this file.

## [1.5.17] - 2026-11-07

### Security
- Identified users in the Cantaloupe cookie fallback only from the `uid` column of the `sessions` table, looked up by hashed session ID. The session payload and unhashed session IDs are no longer used.
- Rejected blocked users in both the cookie and token authorization paths.
- Enforced source-based authorization on IIIF manifests, including requests that name a different subsystem. Manifests for restricted sources now return 403 to users who cannot view them.

### Changed
- The cookie fallback now requires Drupal's default database session storage. Sites using another session handler must rely on tokens.
- Manifests for restricted subsystems are no longer public. This reverses the earlier design that kept every manifest reachable for external viewers; manifests for subsystems that allow anonymous access are unchanged.
- Documented how to close the authorization endpoint to the public when the delegate uses the loopback vhost.

### セキュリティ (日本語)
- Cantaloupe の Cookie 認可で、利用者の判定を `sessions` テーブルの `uid` 列のみに限定しました。検索にはハッシュ化したセッション ID を使い、セッションの中身とハッシュ化していない ID は使いません。
- Cookie とトークンの両方の認可で、無効化されたユーザーを拒否します。
- IIIF マニフェストに資料単位の認可を追加しました。別サブシステム名の指定も拒否し、閲覧できない制限資料は 403 を返します。

### 変更 (日本語)
- Cookie 認可は Drupal 標準のデータベースセッションが必須になりました。別のセッションハンドラを使うサイトはトークンを利用してください。
- 制限付きサブシステムのマニフェストは公開しなくなりました。外部ビューア向けにすべてのマニフェストを公開していた従来の設計からの変更です。匿名アクセスを許可するサブシステムのマニフェストは変わりません。
- delegate がループバックの vhost を使う場合に、認可 API を公開側から閉じる手順を記載しました。

## [1.5.16] - 2026-11-06

### Security
- Removed Cantaloupe authorization bypasses based on `info.json` URL suffixes, query-string matches, and loopback client addresses. Explicit public-directory exceptions now match validated image identifier segments only.
- Added shared source-based authorization to annotation reads, full text, exports, dialogs, source pages, search results, and autocomplete. Requests cannot substitute another subsystem to access a restricted source.
- Required both operation permissions and subsystem authorization when creating, updating, or deleting annotations and opening the editor.
- Required session CSRF headers for all annotation mutations and updated the editor to obtain and send the session token. Rejected non-JSON and malformed polygon payloads.
- Denied access when a configured Drupal Group or membership loader is unavailable; restricted subsystems now always require login. Membership decisions and pages containing signed tokens are not shared through render caches.

### Changed
- Bounded search page sizes to 1–200 results and applied source visibility before counting and pagination.
- Documented delegate replacement, cache rebuilds, and private IIIF cache settings. Existing Cantaloupe deployments must replace their delegate and rebuild Drupal caches after updating.

### セキュリティ (日本語)
- Cantaloupe の URL 末尾・クエリ文字列・localhost による認証回避を修正しました。公開例外は検証済みの画像識別子のパス要素で判定します。
- アノテーション・本文・エクスポート・詳細パネル・資料ページ・検索・補完に、実際の資料に基づく共通の認可処理を追加しました。別サブシステム名の指定による制限回避も拒否します。
- アノテーションの作成・更新・削除と編集画面で、操作権限とサブシステムの認可を必須にしました。
- 更新 API に CSRF ヘッダーを必須化し、編集画面もセッショントークンの取得・送信に対応しました。JSON 以外の本文と不正なポリゴン座標を拒否します。
- 設定済み Group やメンバー判定機能が利用できない場合はアクセスを拒否します。制限付きサブシステムはログイン必須とし、メンバー判定や署名付きトークンを含む画面のキャッシュ共有を防ぎます。

### 変更 (日本語)
- 検索件数を 1～200 件に制限し、総件数・ページングの前に資料の閲覧制限を適用します。
- 更新手順と非公開 IIIF 画像のキャッシュ設定を記載しました。更新後は配備済み delegate の差し替えと Drupal キャッシュの再構築が必要です。

## [1.5.15] - 2026-08-08
### Fixed
- Cantaloupe delegate sample: defined every method Cantaloupe 5.x invokes on `CustomDelegate` (`metadata`, `redactions`, `overlay`, `source`, the `*source_*` lookups, and the meta-identifier serializers), carrying over their upstream documentation comments. Previously only `pre_authorize`/`authorize` were defined, so a missing `metadata` raised `NoMethodError` inside JRuby and Cantaloupe returned HTTP 500 for **every** `info.json` request while tiles kept rendering normally.

### Added
- Cantaloupe delegate sample: authorization-result cache (`WDB_AUTH_CACHE_TTL`, default 60 s, `0` to disable), keyed on identifier + token + cookies. A single IIIF viewport pulls ~20 tiles and the delegate is called for each, so this collapses ~20 Drupal bootstraps per page view into one. Note the trade-off: a decision can outlive the state it was based on by up to the TTL, so keep it well below `token_ttl`.
- Cantaloupe delegate sample: `WDB_AUTH_HOST_HEADER` to send an explicit `Host` header, so the authorization endpoint can be reached over a plain-HTTP loopback vhost instead of the public HTTPS URL (~25–30 ms → a few ms per IIIF request on a reference deployment).
- Cantaloupe delegate sample: `WDB_SKIP_AUTH_DIRS` to serve named identifier path segments with no authorization check.
- `delegate_harness.rb`: delegate method coverage check, run on every invocation and available standalone via `--check-methods`, to catch the missing-stub failure above before deployment.

### Changed
- README: documented the loopback authorization vhost, proxy connection reuse (`enablereuse`), the `no-store` rule that silently disables IIIF caching, the `Header always` pitfall that emits `Cache-Control` twice, enabling HTTP/2 via `Protocols`, and using `RollingFileAppender` so a per-request delegate error cannot fill the disk. Added a table of the delegate's environment variables and troubleshooting entries for `NoMethodError` and `ECONNREFUSED`.

### 修正 (日本語)
- Cantaloupe delegate サンプル: Cantaloupe 5.x が `CustomDelegate` に対して呼び出すメソッドをすべて定義しました（`metadata`、`redactions`、`overlay`、`source`、各 `*source_*` ルックアップ、メタ識別子のシリアライザ）。上流のドキュメントコメントもそのまま移植しています。従来は `pre_authorize`／`authorize` のみを定義していたため、`metadata` の欠落により JRuby 内で `NoMethodError` が発生し、タイルは正常に描画されるにもかかわらず **すべての `info.json` リクエストが HTTP 500** になっていました。

### 追加 (日本語)
- Cantaloupe delegate サンプル: 認可結果のキャッシュを追加しました（`WDB_AUTH_CACHE_TTL`、既定 60 秒、`0` で無効）。キーは識別子＋トークン＋Cookie です。IIIF ビューアは 1 画面で約 20 枚のタイルを取得し、その 1 枚ごとに delegate が呼ばれるため、1 ページ表示あたり約 20 回だった Drupal のフルブートストラップが 1 回に集約されます。ただし判定結果は根拠となった状態より最大 TTL 秒だけ長く残るため、`token_ttl` より十分小さい値にしてください。
- Cantaloupe delegate サンプル: `Host` ヘッダーを明示する `WDB_AUTH_HOST_HEADER` を追加し、認可エンドポイントを公開 HTTPS URL ではなく平文 HTTP のループバック vhost 経由で呼べるようにしました（ある実環境で IIIF リクエストあたり約 25〜30ms → 数ms）。
- Cantaloupe delegate サンプル: 指定した識別子のパス要素を認可チェックなしで配信する `WDB_SKIP_AUTH_DIRS` を追加しました。
- `delegate_harness.rb`: delegate のメソッド網羅性検査を追加しました。毎回の実行時に検査し、`--check-methods` で単独実行もできます。上記のスタブ欠落を配備前に検出できます。

### 変更 (日本語)
- README: 認可用ループバック vhost、プロキシの接続再利用（`enablereuse`）、IIIF のキャッシュを意図せず無効化する `no-store` 指定、`Cache-Control` が 2 本出てしまう `Header always` の落とし穴、`Protocols` による HTTP/2 の有効化、毎リクエストの delegate エラーでディスクを埋めないための `RollingFileAppender` について記載しました。delegate の環境変数一覧表と、`NoMethodError`／`ECONNREFUSED` のトラブルシューティング項目も追加しています。

## [1.5.14] - 2026-07-23
### Fixed
- Thumbnails (word/sign/constituent-sign, pager, and search results) are now requested in CORS mode via `crossorigin="anonymous"`, matching the OpenSeadragon viewer. This prevents `ERR_BLOCKED_BY_RESPONSE.NotSameSite` errors when the IIIF image server responds with a cross-origin `Cross-Origin-Resource-Policy` header.

### 修正 (日本語)
- 単語／文字／構成文字サムネイル、ページャーサムネイル、検索結果サムネイルを、ビューア（OpenSeadragon）と同様に `crossorigin="anonymous"`（CORSモード）で読み込むようにしました。IIIF 画像サーバーが `Cross-Origin-Resource-Policy` ヘッダーを返す場合に発生する `ERR_BLOCKED_BY_RESPONSE.NotSameSite` によるサムネイル読み込み失敗を防止します。

## [1.5.13] - 2025-12-23
### Fixed
- Cantaloupe delegate: added an `authorize()` entrypoint (aliasing to `pre_authorize()`) to stay compatible with installations that invoke `authorize`, preventing 500 errors.
- Viewer (gallery): replaced `scrollIntoView()` in the annotation-panel update flow with a scroll confined to the full-text container, preventing the panel toolbar from being scrolled out of view.

### 修正 (日本語)
- Cantaloupe delegate: `authorize()` が呼ばれる環境でも動作するよう、`authorize()` エントリポイントを追加（`pre_authorize()` へ委譲）し、500 エラーを防止しました。
- ビューア（ギャラリー）: アノテーションパネル更新時の `scrollIntoView()` によりツールバーがスクロールアウトすることがあるため、フルテキスト欄のスクロールだけを行う実装に変更しました。

## [1.5.12] - 2025-12-14
### Changed
- TSV import now persists `wdb_annotation_page.image_identifier`: it prefers the TSV value when present, otherwise attempts to generate and save it from the subsystem IIIF identifier pattern. Existing values are never overwritten (mismatches emit a warning).
- Template TSV generation now includes `image_identifier` (placed after `page`) and outputs stored values only, avoiding any dependency on subsystem settings.
- README and the import form help text updated to document the optional `image_identifier` column and its column order.

### Fixed
- `WdbProtectedDeleteForm` now redirects back to the entity collection (admin list) after cancel/delete when available, avoiding confusing 404/back-cache behavior when deleting from list pages.
- `WdbProtectedDeleteForm` now emits an explicit success/error Messenger message (and watchdog log) on submit so admins can tell whether a delete actually occurred.
- The WDB Annotation Page admin list (`/admin/content/wdb_annotation_page`) is now explicitly uncacheable to prevent stale paged listings after create/delete.

### 変更 (日本語)
- TSV 取込で `wdb_annotation_page.image_identifier` を永続化するように変更しました。TSV に値があればそれを優先し、無い場合はサブシステムの IIIF Identifier Pattern から生成できる場合に生成して保存します。既存値は上書きせず、不一致は warning として通知します。
- テンプレ TSV 生成に `image_identifier` 列を追加しました（`page` の次）。テンプレ生成は保存済み値のみを出力し、サブシステム設定への依存を避けています。
- README と取込フォームの説明文を更新し、任意列 `image_identifier` と列順を明記しました。

### 修正 (日本語)
- `WdbProtectedDeleteForm` のキャンセル／削除後の遷移先を、可能な場合はエンティティの一覧（collection / 管理リスト）へ戻るように変更し、一覧から削除した際の 404 表示やブラウザの戻るキャッシュによる「削除できていないように見える」挙動を避けました。
- `WdbProtectedDeleteForm` の送信時に、削除成功／失敗が分かる Messenger 通知（および watchdog ログ）を追加し、実際に削除が行われたか判別できるようにしました。
- アノテーションページの管理一覧（`/admin/content/wdb_annotation_page`）を明示的にキャッシュ無効化し、追加／削除後にページ付きURL（`?page=N`）で古い一覧が残って見える問題を防止しました。

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
