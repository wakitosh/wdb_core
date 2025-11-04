# Changelog

All notable changes to WDB Core will be documented in this file.

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
