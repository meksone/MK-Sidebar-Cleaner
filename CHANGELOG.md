# Changelog — MK Sidebar Cleaner

## [1.2.9] — 2026-04-08

### Fixed
- **Sidebar doesn't expand when clicking a folder in collapsed state**: the accordion click handler was calling `e.preventDefault()` + `e.stopPropagation()` unconditionally, blocking WP's native sidebar-expand mechanism when `body.folded` is active. The handler now returns early (letting WP handle the click normally) when the sidebar is collapsed; accordion open/close logic only runs when the sidebar is already expanded.

## [1.2.8] — 2026-04-08

### Changed
- **Position via drag instead of numeric field**: custom group position in the real sidebar is now determined by dragging the group's placeholder item within the Main Sidebar zone, replacing the old numeric "Pos:" input. The rules engine maps the placeholder's drag-order index to a WP menu position at apply time.
- **Main Sidebar placeholder for custom groups**: each custom group now has a dedicated draggable/hideable placeholder item in the Main Sidebar column. Deleting a group also removes its placeholder. This approach mirrors how WP's own separators work positionally.

### Fixed
- **CSS zone squashing**: zone columns now use `flex: 0 0 260px` + `min-width: 260px` to prevent horizontal compression when many groups are present.
- **Scrollbar always visible**: `.mksc-scrolling-board` uses `overflow-x: scroll` (was `auto`) so the horizontal scrollbar is always rendered, making it obvious that more zones exist off-screen.

## [1.2.5] — 2026-04-08

### Fixed
- **Broken links for plugin sub-page children** (`wp-admin/slug` instead of `admin.php?page=slug`): when a plugin registers its sub-pages under its own parent (e.g. `add_submenu_page('dynamic-shortcodes', ..., 'dynamic-shortcodes-power', ...)`), moving the parent into a custom group or built-in target caused WP to output the raw slug as `href` (broken relative path). Root cause: `wp-admin/menu-header.php` calls `get_plugin_page_hook($slug, $new_parent)` and, finding no hook registered under the new parent, falls back to `href='{$slug}'`. Fix: bare plugin-page slugs are now replaced with their canonical URL (via `menu_page_url`) or `admin.php?page={slug}` before being stored in the target submenu, so WP uses the URL as-is without hook lookup. Links containing `.php` or `http` are left unchanged.

## [1.2.4] — 2026-04-08

### Fixed
- **Sub-items invisible in built-in targets**: when a menu item with its own sub-items (e.g. Appearance/Themes) was moved to Settings or Tools, all its children were wrapped with `mksc-nested-item data-mksc-child=...` and then hidden by the collapsible JS on page load. Since there is no expand toggle for built-in targets, the children became permanently invisible. Children moved to built-in targets now use a `mksc-flat-child` span instead — always visible, same CSS indentation, not touched by the collapse/expand JS.
- **Order not applied to Settings/Tools zones**: the saved order for items moved under Settings (`options-general.php`) or Tools (`tools.php`) was recorded by JS but never applied by `apply_order`. Built-in target zones are now reordered: native WP entries keep their original positions, moved entries are resequenced according to the saved zone order.

## [1.2.3] — 2026-04-07

### Fixed
- **Order resets in UI**: the settings page now sorts items within each zone by the saved order, so drag positions are correctly reflected when the page is reloaded. Previously, items always appeared in WP's natural menu position order, causing any re-save to overwrite the stored order with the wrong sequence.
- **Separator corruption in main menu**: `apply_order` was including WP separator entries (position keys 4, 9, 59, 79…) in the real-item position pool. Real items were being assigned to separator positions and separators were dropped, breaking the menu layout. Separators are now kept at their original keys and excluded from redistribution.
- **Items disappearing from custom groups**: child entries (those with `mksc-nested-item` span) were grouped via a positional-sequence heuristic that broke when WooCommerce registers a submenu entry with the same slug as the parent (`woocommerce`). Grouping now uses the `data-mksc-child` attribute set by `apply_moves`, which is unambiguous. Items not in `zone_order` are appended at the end instead of being silently dropped.

## [1.2.2] — 2026-04-08

### Fixed
- Drag-and-drop order was never saved or applied. The JS now serializes the slug order of every zone into `state.order` (keyed by zone target, `__main__` for the main sidebar). The rules engine applies this order: top-level `$menu` position keys are redistributed to match the dragged sequence; custom group `$submenu` entries are rebuilt in zone order, keeping each parent grouped with its children.

## [1.2.1] — 2026-04-08

### Fixed
- Added a blue info notice at the top of the settings page explaining that the sidebar rules are intentionally suspended while on this page, to avoid confusion when comparing the settings page sidebar with the rest of the admin.
- Removed stale "who is not the superadmin" wording from the Default Config tab description.

## [1.2.0] — 2026-04-08

### Changed
- **Superadmin bypass removed**: superadmin is now treated identically to any other admin (personal config → default config fallback). No special-case logic remains.
- `OPTION_BYPASS`, `is_bypass_enabled()`, `set_bypass()`, `handle_toggle_bypass()` and all related UI removed.

### Added
- **Collapsible submenus in the real sidebar**: when a menu item with its own submenus is moved into a custom group, the item row becomes a click-to-expand toggle (▶/▼). Children are hidden by default; expanded state is persisted in `localStorage` per slug. This only applies to custom groups (not Settings/Tools moves), matching how click-based custom menus work.

## [1.1.4] — 2026-04-08

### Fixed
- Bypass toggle form was placed inside `<h1>`, which is invalid HTML; browsers silently moved the `<form>` out of the heading, detaching the submit button so the POST never fired. Restructured into a `.mksc-page-header` flex wrapper that holds the `<h1>` and `<form>` as siblings.

## [1.1.3] — 2026-04-08

### Added
- Superadmin bypass toggle: when logged in as the superadmin account, the page header now shows a pill button that switches between **Bypass** (full sidebar, no filtering) and **Apply** (settings are applied like any other user). State is stored as a site option (`mk_sidebar_cleaner_bypass`), defaults to bypass ON. Badge in the title updates accordingly (orange = bypass, blue = applied).

## [1.1.2] — 2026-04-08

### Added
- Draggable items now show only top-level menu entries (submenus are no longer listed as separate draggable rows).
- Each item with submenus shows a ▶ toggle button; clicking it expands/collapses the submenu list inline (read-only, informational).
- Expanded/collapsed state is persisted per-slug in `localStorage` and restored on page load.

## [1.1.1] — 2026-04-08

### Fixed
- Moved menu items no longer flatten their submenus as unrelated siblings. Sub-items are now placed immediately after the parent entry and visually indented with a `└` connector via a `<span class="mksc-nested-item">` wrapper + `admin_head` CSS. WP admin's 2-level limit means true nesting isn't possible natively; this approach preserves apparent hierarchy without patching core.

## [1.1.0] — 2026-04-08

### Added
- **Inline rename**: double-click any sidebar item name to rename it in-place; the custom name is displayed in the real sidebar and persisted in config (`renamed` map).
- **Group rename**: double-click a custom group header to rename the group inline.
- **Group position control**: each custom group zone now shows a numeric "Pos:" input to set the sidebar position (0–200).
- **Custom groups in Main Sidebar**: when a custom group is created, it also appears as a draggable/hideable item in the Main Sidebar zone, so its position relative to other items can be controlled.

### Changed
- Config schema extended with `renamed{}` (slug → custom display name).
- Rules engine applies renames to `$menu` global at priority 999.
- Item tooltip changed from URL to "Double-click to rename" hint; item name cursor changed to `text`.

## [1.0.2] — 2026-04-08

### Added
- Menu item name now shows the full admin URL as a native browser tooltip on hover.

## [1.0.1] — 2026-04-08

### Added
- **Move menu items**: top-level items can now be relocated under Settings, Tools, or a custom group; their own submenus follow as siblings under the new parent.
- **Custom groups**: create new top-level sidebar groups as relocation targets; groups persist in config and are registered via `add_menu_page` at runtime.
- **Drag-and-drop UI**: jQuery UI Sortable (WP-bundled) powers the zone-based interface — drag items between Main Sidebar, Settings, Tools, and custom groups.
- **Tab layout**: Personal Config and Default Config are now separate tabs instead of side-by-side panels.

### Changed
- Plugin restructured into separate class files: `class-config.php`, `class-rules-engine.php`, `class-admin-page.php`.
- Config schema extended: `hidden[]`, `moved{}`, `custom_groups[]`, `updated`.
- Assets moved to `assets/admin.css` and `assets/admin.js` (enqueued via `wp_enqueue_*`, no more inline styles).
- Rules engine now skips the settings page so the full unmodified menu is always visible while editing.
- If a slug is both marked `hidden` and `moved`, the move takes precedence.

## [1.0.0] — initial release

- Hide menu items per-admin or globally via checkbox table.
- Superadmin bypass (`fedcon-adm`).
- Personal config (user meta) with fallback to global default (site option).
