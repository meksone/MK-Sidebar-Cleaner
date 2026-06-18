# MK Sidebar Cleaner — Debug Handoff

## Context
This is a WordPress admin sidebar management plugin. It was working correctly up to v1.2.1. In v1.2.2 something broke (unknown symptom — the user said "something is broken"). Your job is to identify and fix the regression.

---

## Plugin location
`wp-content/plugins/mk-sidebar-cleaner/` (or wherever installed)

```
mk-sidebar-cleaner.php        ← bootstrap, defines MKSC_VERSION (currently 1.2.2)
includes/
  class-config.php            ← read/write/sanitize config from DB
  class-rules-engine.php      ← applies config to WP $menu/$submenu globals
  class-admin-page.php        ← settings page UI, form handlers
assets/
  admin.css / admin.js        ← drag-and-drop UI (jQuery UI Sortable)
```

---

## Config schema (stored in DB)
```php
[
  'hidden'        => string[],           // top-level slugs to remove
  'moved'         => [ slug => target ], // top-level slug → parent slug
  'renamed'       => [ slug => name ],   // custom display names
  'custom_groups' => [ { slug, name, icon, position } ], // custom top-level groups
  'order'         => [ zone_key => slug[] ], // ← ADDED IN 1.2.2, see below
  'updated'       => int,
]
```

Stored as:
- **Default config**: `get_option('mk_sidebar_cleaner_default')`
- **Personal config**: `get_user_meta($uid, 'mk_sidebar_cleaner_config', true)`

Superadmin (`fedcon-adm`) is treated **identically** to any other admin — no bypass logic. Personal config → default config fallback.

---

## What changed in 1.2.2 (the suspected regression)

### Problem it was trying to solve
Drag-and-drop order within zones was never being saved or applied. Items always appeared in WP's original registration order.

### Changes made

**assets/admin.js** — on form submit, now also serializes zone item order:
```js
state.order = {};
// For each .mksc-zone:
//   key = zone data-target, but '' (main sidebar) becomes '__main__'
//   value = array of slugs in DOM order
state.order[ zoneKey ] = [ slug1, slug2, ... ];
```

**includes/class-config.php** — `sanitize_from_post()` now reads and sanitizes `order`:
```php
$order = [];
foreach ( $raw['order'] as $zone_key => $slugs ) {
    $order[ sanitize_text_field($zone_key) ] = array_map('sanitize_text_field', $slugs);
}
// included in returned array as 'order' => $order
```

**includes/class-rules-engine.php** — new `apply_order()` method, called last in `apply()`:

```php
private function apply_order( array $order, array $custom_slugs ): void {
    global $menu, $submenu;

    // MAIN SIDEBAR: reorder $menu using '__main__' slug list
    // Takes existing position keys, redistributes them in slug order
    // Items not in order list are appended at end

    // CUSTOM GROUPS: for each custom group slug, rebuild $submenu[$group_slug]
    // in zone_order sequence, keeping each parent entry grouped with
    // its immediately-following children (the mksc-nested-item spans)
}
```

---

## Most likely causes of the regression

### 1. `apply_order` runs AFTER `apply_hides`
`remove_menu_page()` modifies `$menu` directly. Then `apply_order()` tries to iterate and reassign the same `$menu`. The position key redistribution may be operating on a partially-modified array, causing items to land in wrong slots or disappear.

**Check**: does removing `apply_order()` (or commenting it out) restore normal behaviour?

### 2. Position key collision with WP separators
WP uses specific numeric keys for separators (e.g. `4`, `9`, `59`, `79`). The reorder logic reuses the existing key set — but if it assigns a separator's key to a real menu item (or vice versa), the separator disappears or items get overwritten.

**Check**: look at `$menu` with `var_dump($menu)` before and after `apply_order()`. Are separators (items where `$item[4] === 'wp-menu-separator'`) being given to real items?

### 3. Custom group submenu rebuild breaks when items were also moved
`apply_moves()` writes entries into `$submenu[$group_slug]`. Then `apply_order()` reads those entries back and tries to group them by matching `$entry[2]` (the slug) against `$zone_order`. If a slug in `zone_order` was for a custom-group placeholder item (not a real moved item), `$groups[$slug]` is never populated and the entry is silently dropped.

**Check**: are items disappearing from custom groups entirely, or just reordering wrongly?

### 4. `order` key never saved (first run after upgrade)
On first load after upgrading to 1.2.2, existing configs have no `order` key. `apply_order()` gets `[]` and does nothing — this is safe. But if the user saved a config before the JS fix deployed (browser cache), `order` might be missing or incomplete. **Clear browser cache** and re-save.

---

## Quickest diagnostic
Add this temporary debug output at the top of `apply_order()`:

```php
private function apply_order( array $order, array $custom_slugs ): void {
    error_log('MKSC apply_order: ' . json_encode([
        'order_keys'    => array_keys($order),
        'custom_slugs'  => $custom_slugs,
        'menu_count'    => count($GLOBALS['menu'] ?? []),
    ]));
    // ... rest of method
```

Then check `wp-content/debug.log` (enable `WP_DEBUG_LOG` if needed).

---

## Known working state
The current DB value for the default config (confirmed via WP CLI):
```
moved:
  novamira-connect        → mk-group-1775638932870
  daextulma-documents     → mk-group-1775638932870
  loco                    → mk-group-1775638932870
  woocommerce             → mk-group-1775639178705
  edit.php?post_type=product → mk-group-1775639178705
  (3 more WooCommerce items) → mk-group-1775639178705

custom_groups:
  mk-group-1775638932870  "⭐Additional Tools"  pos 120
  mk-group-1775639178705  "💲Negozio"           pos 110
```

No personal config exists for fedcon-adm — default config applies to everyone.

---

## Rules engine execution order (for reference)
```
admin_menu priority 999:
  1. register_custom_groups()   — add_menu_page() for each custom group
  2. apply_moves()              — unset from $menu, add to $submenu[target]
  3. apply_renames()            — modify $menu[pos][0] labels
  4. apply_hides()              — remove_menu_page() for hidden slugs
  5. apply_order()              — reorder $menu and $submenu[custom_groups]

admin_head:
  6. admin_head_output()        — emit CSS + JS for collapsible sidebar items
                                  (skips entirely if no 'moved' in active config)
```

Settings page (`tools.php?page=mk-sidebar-cleaner`) skips steps 1–5 entirely so the full unfiltered menu is visible while editing.
