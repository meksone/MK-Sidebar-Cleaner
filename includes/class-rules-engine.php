<?php
defined( 'ABSPATH' ) || exit;

/**
 * Applies the saved config to the live WP admin menu globals.
 *
 * Execution order (priority 999, after all plugins register their menus):
 *   1. Register any custom top-level groups
 *   2. Move items from top-level into submenu of a target
 *      → sub-items of the moved item are placed after it, visually indented
 *        via <span class="mksc-nested-item"> + CSS (WP admin = 2 levels max)
 *   3. Apply renames to $menu
 *   4. Hide remaining items (slugs marked hidden and NOT moved)
 */
class MK_Sidebar_Cleaner_Rules_Engine {

	private MK_Sidebar_Cleaner_Config $config;

	public function __construct( MK_Sidebar_Cleaner_Config $config ) {
		$this->config = $config;
	}

	public function hook(): void {
		add_action( 'admin_menu', [ $this, 'apply' ], 999 );
		add_action( 'admin_head', [ $this, 'admin_head_output' ] );
	}

	public function apply(): void {
		// Skip on our own settings page so the full menu is visible for editing.
		if ( ( $_GET['page'] ?? '' ) === MK_Sidebar_Cleaner_Config::PAGE_SLUG ) return;

		$cfg = $this->config->get_active();
		if ( empty( $cfg ) ) return;

		$moved         = $cfg['moved']         ?? [];
		$custom_groups = $cfg['custom_groups'] ?? [];

		// Derive custom group positions from the Main Sidebar drag order so the
		// placeholder-based positioning works without a numeric field.
		$main_order = $cfg['order']['__main__'] ?? [];
		if ( ! empty( $main_order ) ) {
			foreach ( $custom_groups as &$g ) {
				$idx = array_search( $g['slug'], $main_order, true );
				if ( $idx !== false ) {
					// Map index to a WP menu position range (10–200).
					$g['position'] = 10 + (int) round( ( $idx / max( count( $main_order ) - 1, 1 ) ) * 190 );
				}
			}
			unset( $g );
		}

		$this->register_custom_groups( $custom_groups );

		$custom_slugs = array_column( $custom_groups, 'slug' );
		$this->apply_moves( $moved, $custom_slugs );
		$this->apply_renames( $cfg['renamed'] ?? [] );

		// Hidden slugs that are also moved: move takes precedence.
		$hidden = array_values(
			array_filter(
				$cfg['hidden'] ?? [],
				fn( $slug ) => ! isset( $moved[ $slug ] )
			)
		);
		$this->apply_hides( $hidden );
		$this->apply_order( $cfg['order'] ?? [], $custom_slugs );
	}

	// -------------------------------------------------------------------------

	private function register_custom_groups( array $groups ): void {
		global $menu, $submenu;
		foreach ( $groups as $g ) {
			add_menu_page(
				$g['name'],
				$g['name'],
				'manage_options',
				$g['slug'],
				'__return_null',
				$g['icon'] ?? 'dashicons-category',
				$g['position'] ?? 30
			);

			// Rimuoviamo il subset array da sottomenu auto-generato da WP per non creare flyout vuoti
			if ( isset( $submenu[ $g['slug'] ] ) ) {
				unset( $submenu[ $g['slug'] ] );
			}

			// Add a specific class to our custom top-level menu items so JS can reliably target them
			// and override native WP hover behavior.
			if ( is_array( $menu ) ) {
				foreach ( $menu as $pos => $item ) {
					if ( ( $item[2] ?? '' ) === $g['slug'] ) {
						// mksc-folder is used by JS accordion (using md5 to avoid special char parsing errors in jQuery)
						$safe_slug = md5( $g['slug'] );
						$menu[ $pos ][4] = trim( ( $item[4] ?? '' ) . ' mksc-folder mksc-folder-' . $safe_slug );
						break;
					}
				}
			}
		}
	}

	private function apply_moves( array $moved, array $custom_slugs = [] ): void {
		global $menu, $submenu;
		if ( empty( $moved ) ) return;

		foreach ( $moved as $source_slug => $target_slug ) {
			// Locate the item in the top-level menu.
			$item_data = null;
			$item_pos  = null;
			foreach ( $menu as $pos => $item ) {
				if ( ( $item[2] ?? '' ) === $source_slug ) {
					$item_data = $item;
					$item_pos  = $pos;
					break;
				}
			}
			if ( $item_data === null ) continue; // plugin may not be active

			$is_custom_target  = in_array( $target_slug, $custom_slugs, true );

			// --- NUOVO APPROCCIO TOP-LEVEL ACCORDION ---
			if ( $is_custom_target ) {
				$folder_pos = false;
				foreach ( $menu as $pos => $item ) {
					if ( ( $item[2] ?? '' ) === $target_slug ) {
						$folder_pos = $pos;
						break;
					}
				}
				
				if ( $folder_pos !== false ) {
					// Rimuove il plugin dalla sua posizione originaria
					unset( $menu[ $item_pos ] );
					
					// Aggancia e marca il plugin come figlio usando un hash sicuro per le classi
					$safe_target = md5( $target_slug );
					$item_data[4] = trim( ( $item_data[4] ?? '' ) . " mksc-child mksc-child-of-" . $safe_target );
					
					// Calcola lo slot decimale per mantenerlo fisicamente contiguo in $menu
					$offset = 0.001;
					$new_pos = (float)$folder_pos + $offset;
					while ( isset( $menu[ (string)$new_pos ] ) ) {
						$offset += 0.001;
						$new_pos = (float)$folder_pos + $offset;
					}
					$menu[ (string)$new_pos ] = $item_data;
				}
				continue; // Finito! Questo non entra più in $submenu
			}

			// --- VECCHIO APPROCCIO (per built-in Targets es. Settings/Tools) ---
			unset( $menu[ $item_pos ] );

			if ( ! isset( $submenu[ $target_slug ] ) ) {
				$submenu[ $target_slug ] = [];
			}

			// Determine next available position in the target submenu.
			$next = empty( $submenu[ $target_slug ] )
				? 100
				: ( max( array_keys( $submenu[ $target_slug ] ) ) + 10 );

			$parent_label = wp_strip_all_tags( $item_data[0] );

			$submenu[ $target_slug ][ $next ] = [
				$parent_label,
				$item_data[1] ?? 'read',
				$source_slug,
			];
			$next += 5;

			// Built-in targets (Settings, Tools): wrap with mksc-flat-child
			foreach ( (array) ( $submenu[ $source_slug ] ?? [] ) as $sub ) {
				$child_slug = $sub[2] ?? '';
				if (
					$child_slug !== ''
					&& false === strpos( $child_slug, '.php' )
					&& false === strpos( $child_slug, 'http' )
				) {
					$canonical = function_exists( 'menu_page_url' )
						? menu_page_url( $child_slug, false )
						: '';
					$sub[2] = $canonical ?: ( 'admin.php?page=' . $child_slug );
				}

				$sub[0] = '<span class="mksc-flat-child">'
					. wp_strip_all_tags( $sub[0] )
					. '</span>';
					
				$submenu[ $target_slug ][ $next ] = $sub;
				$next += 5;
			}
		}
	}

	private function apply_order( array $order, array $custom_slugs ): void {
		global $menu, $submenu;

		// --- Top-level menu (Main Sidebar zone, stored under '__main__') ---
		$main_order = $order['__main__'] ?? [];
		if ( ! empty( $main_order ) && is_array( $menu ) ) {
			$separator_entries = [];
			$by_slug           = [];
			$real_positions    = [];
			$folder_children   = []; // New mapping per top-level accordion

			foreach ( $menu as $pos => $item ) {
				$slug = $item[2] ?? '';
				if ( $slug === '' || str_starts_with( $slug, 'separator' ) ) {
					$separator_entries[ $pos ] = $item;
				} else {
					// Check it is a child belonging to a folder
					if ( strpos( $item[4] ?? '', 'mksc-child-of-' ) !== false && preg_match('/mksc-child-of-([a-f0-9]{32})/', $item[4], $m) ) {
						$folder_children[ $m[1] ][] = $item;
					} else {
						$by_slug[ $slug ] = $item;
					}
					// Add ALL real keys to reserve spatial integrity
					$real_positions[] = $pos;
				}
			}
			// Ordinal sorting maintains decimals safely
			sort( $real_positions );
			$pos_pool = $real_positions;

			$new_menu = $separator_entries;
			$pool_idx = 0;

			$inject_with_children = function( $slug, $item ) use ( &$new_menu, &$pool_idx, $pos_pool, $folder_children ) {
				if ( $pool_idx < count( $pos_pool ) ) {
					$new_menu[ (string) $pos_pool[ $pool_idx++ ] ] = $item;
				}
				$safe_slug = md5( $slug ); // I figli sono mappati usando l'hash sicuro
				if ( isset( $folder_children[ $safe_slug ] ) ) {
					foreach ( $folder_children[ $safe_slug ] as $child_item ) {
						if ( $pool_idx < count( $pos_pool ) ) {
							$new_menu[ (string) $pos_pool[ $pool_idx++ ] ] = $child_item;
						}
					}
				}
			};

			// First: items explicitly ordered by the user.
			foreach ( $main_order as $slug ) {
				if ( isset( $by_slug[ $slug ] ) ) {
					$inject_with_children( $slug, $by_slug[ $slug ] );
					unset( $by_slug[ $slug ] );
				}
			}
			
			// Then: any remaining items not in the order list.
			foreach ( $by_slug as $slug => $item ) {
				$inject_with_children( $slug, $item );
			}

			// We need ksort fallback since custom decimals might cause WP minor structural sorting glitches if skipped
			ksort($new_menu, SORT_NUMERIC);
			$menu = $new_menu;
		}

		// --- Built-in target zones (Settings, Tools) ---
		// Reorder entries added by apply_moves; existing WP-native entries keep
		// their original positions (they are not in zone_order).
		$builtin_targets = [ 'options-general.php', 'tools.php' ];
		foreach ( $builtin_targets as $bt_slug ) {
			$zone_order = $order[ $bt_slug ] ?? [];
			if ( empty( $zone_order ) || empty( $submenu[ $bt_slug ] ) ) continue;

			// Split entries: those we placed (flat-child or parent from apply_moves)
			// vs native WP entries. Native entries keep their original positions;
			// only the moved entries are resequenced.
			$native     = [];
			$by_moved   = []; // source_slug → [ parent_entry, flat_child_entries... ]
			$last_moved = null;

			foreach ( $submenu[ $bt_slug ] as $pos => $entry ) {
				$label = $entry[0] ?? '';
				if ( strpos( $label, 'mksc-flat-child' ) !== false ) {
					// Child of a moved item — attach to last seen moved parent.
					if ( ! empty( $last_moved ) ) {
						$by_moved[ $last_moved ][] = $entry;
					}
				} elseif ( in_array( $entry[2] ?? '', $zone_order, true ) ) {
					// Parent entry placed by apply_moves.
					$last_moved                    = $entry[2];
					$by_moved[ $last_moved ]       = isset( $by_moved[ $last_moved ] )
						? array_merge( [ $entry ], $by_moved[ $last_moved ] )
						: [ $entry ];
				} else {
					$native[ $pos ] = $entry;
					$last_moved     = null;
				}
			}

			// Determine insertion position: append after the last native entry.
			$next = empty( $native ) ? 10 : ( max( array_keys( $native ) ) + 10 );

			$new_sub = $native;
			foreach ( $zone_order as $slug ) {
				if ( ! isset( $by_moved[ $slug ] ) ) continue;
				foreach ( $by_moved[ $slug ] as $entry ) {
					$new_sub[ $next ] = $entry;
					$next += 5;
				}
				unset( $by_moved[ $slug ] );
			}
			// Append any moved entries not in zone_order.
			foreach ( $by_moved as $group_entries ) {
				foreach ( $group_entries as $entry ) {
					$new_sub[ $next ] = $entry;
					$next += 5;
				}
			}
			$submenu[ $bt_slug ] = $new_sub;
		}

		// --- Custom group submenus ---
		foreach ( $custom_slugs as $group_slug ) {
			$zone_order = $order[ $group_slug ] ?? [];
			if ( empty( $zone_order ) || empty( $submenu[ $group_slug ] ) ) continue;

			// Build lookup: source_slug → [parent_entry, child_entries...].
			// Use the data-mksc-child attribute set by apply_moves to reliably
			// distinguish children from parents — avoids false-positive slug matches
			// (e.g. WooCommerce registers a submenu entry whose slug equals 'woocommerce').
			$entries = $submenu[ $group_slug ];
			$groups  = []; // source_slug → [ parent_entry, child_entry, ... ]

			foreach ( $entries as $entry ) {
				$label = $entry[0] ?? '';
				if ( strpos( $label, 'mksc-nested-item' ) !== false ) {
					// Child entry — route to its parent via data-mksc-child.
					if ( preg_match( '/data-mksc-child="([^"]+)"/', $label, $m ) ) {
						$parent_slug              = $m[1];
						$groups[ $parent_slug ][] = $entry;
					}
				} else {
					// Parent entry.
					$entry_slug = $entry[2] ?? '';
					if ( ! isset( $groups[ $entry_slug ] ) ) {
						$groups[ $entry_slug ] = [ $entry ];
					} else {
						// Parent arrived after pre-registered children — put it first.
						array_unshift( $groups[ $entry_slug ], $entry );
					}
				}
			}

			// Rebuild submenu: ordered items first, then any items not in zone_order.
			$new_sub = [];
			$next    = 100;
			foreach ( $zone_order as $slug ) {
				if ( ! isset( $groups[ $slug ] ) ) continue;
				foreach ( $groups[ $slug ] as $entry ) {
					$new_sub[ $next ] = $entry;
					$next += 5;
				}
				unset( $groups[ $slug ] );
			}
			// Append items that exist in the submenu but were not in zone_order
			// (e.g. newly activated plugins added since the last save).
			foreach ( $groups as $group_entries ) {
				foreach ( $group_entries as $entry ) {
					$new_sub[ $next ] = $entry;
					$next += 5;
				}
			}
			$submenu[ $group_slug ] = $new_sub;
		}
	}

	private function apply_hides( array $hidden ): void {
		foreach ( $hidden as $slug ) {
			remove_menu_page( $slug );
		}
	}

	/**
	 * Output CSS + JS for nested/collapsible sidebar items.
	 * Runs on every admin page so the real sidebar is always correctly styled.
	 */
	public function admin_head_output(): void {
		$cfg = $this->config->get_active();
		if ( empty( $cfg['moved'] ) ) return;
		?>
<style id="mksc-nested-css">
/* Indent moved-item children inside custom groups (collapsible, hidden by default) */
#adminmenu .mksc-nested-item {
	display: block;
	padding-left: 14px;
	position: relative;
	opacity: .85;
}
#adminmenu .mksc-nested-item::before {
	content: "\2514"; /* └ */
	position: absolute;
	left: 3px;
	top: 0;
	font-size: 9px;
	line-height: inherit;
	opacity: .45;
}
#adminmenu li.current a .mksc-nested-item { opacity: 1; }

/* Indent moved-item children inside built-in targets (Settings, Tools) — always visible */
#adminmenu .mksc-flat-child {
	display: block;
	padding-left: 14px;
	position: relative;
	opacity: .85;
}
#adminmenu .mksc-flat-child::before {
	content: "\2514";
	position: absolute;
	left: 3px;
	top: 0;
	font-size: 9px;
	line-height: inherit;
	opacity: .45;
}
#adminmenu li.current a .mksc-flat-child { opacity: 1; }

/* Forced indicator arrow for folders */
#adminmenu li.mksc-folder > a.menu-top::after {
	content: "\25BC";
	position: absolute;
	right: 10px;
	top: 50%;
	transform: translateY(-50%) rotate(-90deg);
	font-size: 10px;
	opacity: 0.5;
	transition: transform 0.2s;
}
#adminmenu li.mksc-folder.mksc-open > a.menu-top::after {
	transform: translateY(-50%) rotate(0deg);
}
body.folded #adminmenu li.mksc-folder > a.menu-top::after { display: none; }

/* Top-Level Accordion child logic */
#adminmenu li.mksc-child {
	display: none; /* Nascondi sempre al primo caricamento */
}
#adminmenu li.mksc-child > a.menu-top {
	padding-left: 30px; /* Rientro visivo a destra */
	opacity: 0.85;
}
#adminmenu li.mksc-child > a.menu-top .wp-menu-image {
	width: 20px; /* Rimpiccioliamo leggermente l'icona */
}
#adminmenu li.mksc-child.current > a.menu-top,
#adminmenu li.mksc-child:hover > a.menu-top {
	opacity: 1;
}
body.folded #adminmenu li.mksc-child > a.menu-top {
	padding-left: 0;
	opacity: 0.75;
}

</style>
<script id="mksc-sidebar-js">
jQuery( function( $ ) {
	var STORE_KEY = 'mksc_open_groups';

	function getOpen() {
		try { return JSON.parse( localStorage.getItem( STORE_KEY ) || '[]' ); }
		catch(e) { return []; }
	}
	function saveOpen( arr ) {
		try { localStorage.setItem( STORE_KEY, JSON.stringify( arr ) ); }
		catch(e) {}
	}

	// --- 1. SOTTOMENU ANNIDATI (Vecchia logica per menu di sistema es. Impostazioni) ---
	function childItems( slug ) {
		return $( '#adminmenu [data-mksc-child="' + slug + '"]' ).closest( 'li' );
	}

	$( '#adminmenu [data-mksc-child]' ).closest( 'li' ).hide().removeClass( 'mksc-child-hidden' );

	var openNested = getOpen();
	openNested.forEach( function( slug ) { 
		try {
			$( '#adminmenu [data-mksc-group="' + slug + '"]' ).addClass( 'mksc-open' );
			childItems( slug ).show();
		} catch(e) {}
	} );

	$( '#adminmenu' ).on( 'click', '[data-mksc-group]', function( e ) {
		e.preventDefault();
		e.stopPropagation();

		var slug    = $( this ).data( 'mksc-group' );
		var isOpen  = $( this ).hasClass( 'mksc-open' );
		var $submenu = $( this ).closest('.wp-submenu');
		var saved   = getOpen();

		if ( isOpen ) {
			$( this ).removeClass( 'mksc-open' );
			childItems( slug ).slideUp( 250 );
			saved = saved.filter( function(s) { return s !== slug; } );
		} else {
			// Accordion
			if ( $submenu.length ) {
				$submenu.find('[data-mksc-group].mksc-open').each(function() {
					var otherSlug = $(this).data('mksc-group');
					if ( otherSlug !== slug ) {
						$(this).removeClass('mksc-open');
						childItems( otherSlug ).slideUp( 250 );
						saved = saved.filter( function(s) { return s !== otherSlug; } );
					}
				});
			}

			$( this ).addClass( 'mksc-open' );
			childItems( slug ).slideDown( 250 );
			if ( saved.indexOf(slug) === -1 ) saved.push( slug );
		}
		
		saveOpen( saved );
	} );

	// --- 2. NUOVE CARTELLE TOP-LEVEL (Main Sidebar Accordion) ---
	
	// Applica stato iniziale (ripristino da localStorage)
	var openFolders = getOpen();
	openFolders.forEach( function( slug ) {
		try {
			$( '#adminmenu li.mksc-folder-' + slug ).addClass( 'mksc-open' );
			$( '#adminmenu li.mksc-child-of-' + slug ).show();
		} catch(e) {
			// Silently fail for legacy unhashed slugs still stuck in the localStorage
		}
	});

	// Trigger al click delle cartelle custom
	$( '#adminmenu' ).on( 'click', 'li.mksc-folder > a.menu-top', function( e ) {
		// When the sidebar is collapsed (folded), let WP handle the click so
		// the sidebar expands normally. Our accordion only runs when expanded.
		if ( $( document.body ).hasClass( 'folded' ) ) return;

		e.preventDefault();
		e.stopPropagation();

		var $li = $( this ).closest( 'li.mksc-folder' );
		var match = $li.attr( 'class' ).match( /mksc-folder-([a-f0-9]{32})/ );
		if ( ! match ) return;
		var slug = match[1];

		var isOpen = $li.hasClass( 'mksc-open' );
		var saved = getOpen().filter( function(s) { return s !== slug; } );

		if ( isOpen ) {
			$li.removeClass( 'mksc-open' );
			$( '#adminmenu li.mksc-child-of-' + slug ).slideUp( 250 );
		} else {
			// Accordion: chiudi le altre cartelle
			$( '#adminmenu li.mksc-folder.mksc-open' ).each( function() {
				$( this ).removeClass( 'mksc-open' );
				var oMatch = $( this ).attr( 'class' ).match( /mksc-folder-([a-f0-9]{32})/ );
				if ( oMatch ) {
					$( '#adminmenu li.mksc-child-of-' + oMatch[1] ).slideUp( 250 );
					saved = saved.filter( function(s) { return s !== oMatch[1]; } );
				}
			} );

			// Apri la cartella corrente
			$li.addClass( 'mksc-open' );
			$( '#adminmenu li.mksc-child-of-' + slug ).slideDown( 250 );
			saved.push( slug );
			
			// Auto-scroll se sta uscendo fuori dallo schermo (in modalità espansa)
			if ( ! $(document.body).hasClass('folded') ) {
				setTimeout(function() {
					var $children = $( '#adminmenu li.mksc-child-of-' + slug );
					if ($children.length) {
						var lastChild = $children.last()[0];
						var rect = lastChild.getBoundingClientRect();
						if ( rect.bottom > window.innerHeight - 20 ) {
							lastChild.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
						}
					}
				}, 260); // Aspetta la fine della transizione
			}
		}

		saveOpen( saved );
	} );

} );
</script>
		<?php
	}

	private function apply_renames( array $renamed ): void {
		global $menu;
		if ( empty( $renamed ) || ! is_array( $menu ) ) return;

		foreach ( $menu as $pos => &$item ) {
			$slug = $item[2] ?? '';
			if ( isset( $renamed[ $slug ] ) ) {
				$item[0] = esc_html( $renamed[ $slug ] );
			}
		}
		unset( $item );
	}
}
