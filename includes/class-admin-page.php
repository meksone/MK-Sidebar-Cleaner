<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings page, enqueues assets, renders the drag-drop UI,
 * and handles save/reset form submissions.
 */
class MK_Sidebar_Cleaner_Admin_Page {

	private MK_Sidebar_Cleaner_Config $config;

	// Built-in destination zones: slug => label + dashicon
	private const BUILTIN_ZONES = [
		''                   => [ 'label' => 'Main Sidebar',    'icon' => 'dashicons-menu'          ],
		'options-general.php'=> [ 'label' => 'Under Settings',  'icon' => 'dashicons-admin-settings' ],
		'tools.php'          => [ 'label' => 'Under Tools',     'icon' => 'dashicons-admin-tools'   ],
	];

	public function __construct( MK_Sidebar_Cleaner_Config $config ) {
		$this->config = $config;
	}

	public function hook(): void {
		add_action( 'admin_menu',            [ $this, 'register_page'  ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_mk_sidebar_save',  [ $this, 'handle_save'  ] );
		add_action( 'admin_post_mk_sidebar_reset', [ $this, 'handle_reset' ] );
		add_action( 'admin_notices',               [ $this, 'show_notice'  ] );
	}

	// -------------------------------------------------------------------------
	// Registration & assets
	// -------------------------------------------------------------------------

	public function register_page(): void {
		add_management_page(
			'MK Sidebar Cleaner',
			'Sidebar Cleaner',
			'manage_options',
			MK_Sidebar_Cleaner_Config::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		// Use strpos for PHP 7.4 compatibility.
		// Temporarily debug the hook to the error log to ensure we know what WP is passing.
		error_log( 'MKSC Enqueue Hook: ' . $hook );
		if ( strpos( $hook, MK_Sidebar_Cleaner_Config::PAGE_SLUG ) === false ) return;

		wp_enqueue_style(
			'mksc-admin',
			MKSC_URL . 'assets/admin.css',
			[],
			MKSC_VERSION
		);
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'mksc-admin',
			MKSC_URL . 'assets/admin.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			MKSC_VERSION,
			true
		);
		wp_localize_script( 'mksc-admin', 'mkscI18n', [
			'addGroupPrompt'      => __( 'Enter a name for the new group:', 'mk-sidebar-cleaner' ),
			'deleteGroupConfirm'  => __( 'Delete this group? Items inside will return to the Main Sidebar.', 'mk-sidebar-cleaner' ),
			'renameTip'           => __( 'Double-click an item name to rename it.', 'mk-sidebar-cleaner' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	public function show_notice(): void {
		if ( ( $_GET['page'] ?? '' ) !== MK_Sidebar_Cleaner_Config::PAGE_SLUG ) return;
		if ( ! isset( $_GET['mk_saved'] ) ) return;
		$label = $_GET['mk_saved'] === 'default' ? 'Default config' : 'Personal config';
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html( $label ),
			esc_html__( 'saved successfully.', 'mk-sidebar-cleaner' )
		);
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

		$tab      = sanitize_key( $_GET['tab'] ?? 'personal' );
		$personal = $this->config->get_personal();
		$default  = $this->config->get_default();
		$cfg      = $tab === 'default' ? $default : $personal;
		$items    = $this->menu_items();
		$base_url = admin_url( 'tools.php?page=' . MK_Sidebar_Cleaner_Config::PAGE_SLUG );
		?>
		<div class="wrap mksc-page">

			<h1 class="mksc-page-title">
				<?php esc_html_e( 'MK Sidebar Cleaner', 'mk-sidebar-cleaner' ); ?>
				<span class="mksc-version">v<?= esc_html( MKSC_VERSION ) ?></span>
			</h1>

			<nav class="nav-tab-wrapper">
				<a href="<?= esc_url( $base_url . '&tab=personal' ) ?>"
				   class="nav-tab <?= $tab === 'personal' ? 'nav-tab-active' : '' ?>">
					&#x1F464; <?php esc_html_e( 'Personal Config', 'mk-sidebar-cleaner' ); ?>
					<?php if ( ! empty( $personal['updated'] ) ) : ?>
						<span class="mksc-tab-ts"><?= esc_html( date( 'M j', $personal['updated'] ) ) ?></span>
					<?php endif; ?>
				</a>
				<a href="<?= esc_url( $base_url . '&tab=default' ) ?>"
				   class="nav-tab <?= $tab === 'default' ? 'nav-tab-active' : '' ?>">
					&#x1F30D; <?php esc_html_e( 'Default Config', 'mk-sidebar-cleaner' ); ?>
					<?php if ( ! empty( $default['updated'] ) ) : ?>
						<span class="mksc-tab-ts"><?= esc_html( date( 'M j', $default['updated'] ) ) ?></span>
					<?php endif; ?>
				</a>
			</nav>

			<div class="notice notice-info inline" style="margin:0 0 8px;">
				<p><?php esc_html_e( 'The sidebar you see right now is the full unmodified menu. Settings are intentionally not applied on this page so you can see all available items. Navigate anywhere else in the admin to see your configuration in effect.', 'mk-sidebar-cleaner' ); ?></p>
			</div>

			<div class="mksc-tab-panel">
				<p class="mksc-tab-desc">
				<?php if ( $tab === 'personal' ) : ?>
					<?php esc_html_e( 'Applies to your account only. If empty, the Default Config is used instead.', 'mk-sidebar-cleaner' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Applies to every user without a personal config.', 'mk-sidebar-cleaner' ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $cfg['updated'] ) ) : ?>
					<em class="mksc-saved-ts"><?php printf(
						/* translators: %s: date/time */
						esc_html__( 'Last saved: %s', 'mk-sidebar-cleaner' ),
						esc_html( date( 'Y-m-d H:i', $cfg['updated'] ) )
					); ?></em>
				<?php endif; ?>
				</p>

				<?php $this->render_form( $tab, $cfg, $items ); ?>
			</div>

			<div class="mksc-legend">
				<strong><?php esc_html_e( 'How it works', 'mk-sidebar-cleaner' ); ?>:</strong>
				<ul>
					<li><strong><?php esc_html_e( 'Admin users', 'mk-sidebar-cleaner' ); ?></strong> &mdash; <?php esc_html_e( 'personal config if set; otherwise falls back to default', 'mk-sidebar-cleaner' ); ?></li>
					<li><strong><?php esc_html_e( 'All other users', 'mk-sidebar-cleaner' ); ?></strong> &mdash; <?php esc_html_e( 'default config applied', 'mk-sidebar-cleaner' ); ?></li>
					<li><?php esc_html_e( 'Drag items between zones to move them. Check &#x1F6AB; to hide them entirely.', 'mk-sidebar-cleaner' ); ?></li>
					<li><?php esc_html_e( 'Items moved into a custom group become collapsible in the real sidebar (click the item name to expand/collapse its submenus).', 'mk-sidebar-cleaner' ); ?></li>
					<li><?php esc_html_e( 'Double-click any item name to rename it. Double-click a custom group header to rename the group.', 'mk-sidebar-cleaner' ); ?></li>
					<li><?php esc_html_e( 'Custom groups appear in the Main Sidebar zone for positioning and can be hidden like any item.', 'mk-sidebar-cleaner' ); ?></li>
				</ul>
			</div>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Form render
	// -------------------------------------------------------------------------

	private function render_form( string $scope, array $cfg, array $items ): void {
		$hidden        = $cfg['hidden']        ?? [];
		$moved         = $cfg['moved']         ?? [];
		$renamed       = $cfg['renamed']       ?? [];
		$custom_groups = $cfg['custom_groups'] ?? [];

		// Build a map of zone_target => items assigned to it.
		$zone_items = [];
		foreach ( array_keys( self::BUILTIN_ZONES ) as $zt ) {
			$zone_items[ $zt ] = [];
		}
		foreach ( $custom_groups as $g ) {
			$zone_items[ $g['slug'] ] = [];
		}

		foreach ( $items as $item ) {
			$target = $moved[ $item['slug'] ] ?? '';
			// Fall back to Main Sidebar if the saved target no longer exists.
			if ( ! array_key_exists( $target, $zone_items ) ) {
				$target = '';
			}
			$item['is_hidden']    = in_array( $item['slug'], $hidden, true );
			$item['custom_name']  = $renamed[ $item['slug'] ] ?? '';
			$zone_items[ $target ][] = $item;
		}

		// Custom groups also appear as draggable placeholder items in the Main Sidebar
		// so their vertical position relative to other items can be set by drag order.
		$group_placeholders = [];
		foreach ( $custom_groups as $g ) {
			$group_placeholders[ $g['slug'] ] = [
				'pos'             => 0,
				'name'            => $g['name'],
				'slug'            => $g['slug'],
				'url'             => '',
				'children'        => [],
				'is_custom_group' => true,
				'is_hidden'       => in_array( $g['slug'], $hidden, true ),
				'custom_name'     => $g['name'],
			];
		}

		// Re-sort each zone's items by the saved order so the UI reflects the
		// last-saved drag position rather than WP's natural menu position.
		$saved_order = $cfg['order'] ?? [];

		$sort_zone = function ( array &$zone_list, array $order_list ): void {
			if ( empty( $order_list ) ) return;
			usort( $zone_list, function ( $a, $b ) use ( $order_list ) {
				$ai = array_search( $a['slug'], $order_list, true );
				$bi = array_search( $b['slug'], $order_list, true );
				$ai = $ai === false ? PHP_INT_MAX : $ai;
				$bi = $bi === false ? PHP_INT_MAX : $bi;
				return $ai <=> $bi;
			} );
		};

		// Inject custom group placeholders into the Main Sidebar zone.
		foreach ( $group_placeholders as $placeholder ) {
			// Only add if not already present (prevents duplicates on re-render).
			$already = false;
			foreach ( $zone_items[''] as $existing ) {
				if ( $existing['slug'] === $placeholder['slug'] ) { $already = true; break; }
			}
			if ( ! $already ) {
				$zone_items[''][] = $placeholder;
			}
		}

		// Main Sidebar zone (JS key '__main__' maps to zone target '').
		$sort_zone( $zone_items[''], $saved_order['__main__'] ?? [] );

		// Built-in target zones (Settings, Tools).
		foreach ( array_keys( self::BUILTIN_ZONES ) as $zt ) {
			if ( $zt === '' ) continue;
			$sort_zone( $zone_items[ $zt ], $saved_order[ $zt ] ?? [] );
		}

		// Custom group zones.
		foreach ( $custom_groups as $g ) {
			$sort_zone( $zone_items[ $g['slug'] ], $saved_order[ $g['slug'] ] ?? [] );
		}

		$reset_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mk_sidebar_reset&mk_scope=' . $scope ),
			'mk_sidebar_save'
		);
		?>
		<form method="post"
		      action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>"
		      class="mksc-form"
		      id="mksc-form-<?= esc_attr( $scope ) ?>">

			<?php wp_nonce_field( 'mk_sidebar_save' ); ?>
			<input type="hidden" name="action"     value="mk_sidebar_save">
			<input type="hidden" name="mk_scope"   value="<?= esc_attr( $scope ) ?>">
			<input type="hidden" name="mksc_state" id="mksc-state-<?= esc_attr( $scope ) ?>" value="">

			<div class="mksc-layout-split">
				
				<!-- LEFT: Main Sidebar Pinned -->
				<div class="mksc-main-board">
					<div class="mksc-zone-col" data-zone-target="">
						<div class="mksc-zone-header">
							<span class="dashicons dashicons-menu"></span>
							<?php esc_html_e( 'Main Sidebar', 'mk-sidebar-cleaner' ); ?>
						</div>
						<ul class="mksc-zone" data-target="">
							<?php foreach ( $zone_items[''] as $item ) : ?>
								<?php $this->render_item( $item ); ?>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				
				<!-- RIGHT: Scrolling destinations -->
				<div class="mksc-scrolling-board">
					<?php foreach ( self::BUILTIN_ZONES as $zone_target => $zone_meta ) : ?>
					<?php if ( $zone_target === '' ) continue; // Già renderizzato a sx ?>
					<div class="mksc-zone-col"
					     data-zone-target="<?= esc_attr( $zone_target ) ?>">
						<div class="mksc-zone-header">
							<span class="dashicons <?= esc_attr( $zone_meta['icon'] ) ?>"></span>
							<?= esc_html( $zone_meta['label'] ) ?>
						</div>
						<ul class="mksc-zone" data-target="<?= esc_attr( $zone_target ) ?>">
							<?php foreach ( $zone_items[ $zone_target ] as $item ) : ?>
								<?php $this->render_item( $item ); ?>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endforeach; ?>

					<?php foreach ( $custom_groups as $g ) : ?>
					<div class="mksc-zone-col mksc-zone-col--custom"
					     data-zone-target="<?= esc_attr( $g['slug'] ) ?>"
					     data-custom-slug="<?= esc_attr( $g['slug'] ) ?>"
					     data-custom-name="<?= esc_attr( $g['name'] ) ?>"
					     data-custom-icon="<?= esc_attr( $g['icon'] ?? 'dashicons-category' ) ?>">
						<div class="mksc-zone-header">
							<span class="dashicons <?= esc_attr( $g['icon'] ?? 'dashicons-category' ) ?>"></span>
							<?= esc_html( $g['name'] ) ?>
							<button type="button" class="mksc-btn-delete-zone" title="<?php esc_attr_e( 'Delete group', 'mk-sidebar-cleaner' ); ?>">✕</button>
						</div>
						<ul class="mksc-zone" data-target="<?= esc_attr( $g['slug'] ) ?>">
							<?php foreach ( $zone_items[ $g['slug'] ] as $item ) : ?>
								<?php $this->render_item( $item ); ?>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endforeach; ?>

					<div class="mksc-add-zone-wrap">
						<button type="button" class="button mksc-btn-add-zone">
							+ <?php esc_html_e( 'Add Group', 'mk-sidebar-cleaner' ); ?>
						</button>
					</div>

				</div><!-- .mksc-scrolling-board -->

			</div><!-- .mksc-layout-split -->

			<div class="mksc-form-footer">
				<button type="submit" class="button button-primary">
					<?php echo $scope === 'default'
						? esc_html__( 'Save Default Config', 'mk-sidebar-cleaner' )
						: esc_html__( 'Save Personal Config', 'mk-sidebar-cleaner' ); ?>
				</button>
				<?php if ( ! empty( $cfg ) ) : ?>
				<a href="<?= esc_url( $reset_url ) ?>"
				   class="button"
				   onclick="return confirm('<?php esc_attr_e( 'Reset this config to empty?', 'mk-sidebar-cleaner' ); ?>');">
					<?php esc_html_e( 'Reset', 'mk-sidebar-cleaner' ); ?>
				</a>
				<?php endif; ?>
			</div>

		</form>
		<?php
	}

	private function render_item( array $item ): void {
		$extra_class  = $item['is_hidden'] ? ' mksc-item--hidden' : '';
		$is_custom    = ! empty( $item['is_custom_group'] );
		$extra_class .= $is_custom ? ' mksc-item--custom-group' : '';
		$display_name = ! empty( $item['custom_name'] ) ? $item['custom_name'] : $item['name'];
		$children     = $item['children'] ?? [];
		$has_children = ! empty( $children );
		?>
		<li class="mksc-item<?= esc_attr( $extra_class ) ?>"
		    data-slug="<?= esc_attr( $item['slug'] ) ?>"
		    data-name="<?= esc_attr( $item['name'] ) ?>"
		    data-custom-name="<?= esc_attr( $display_name ) ?>">
			<div class="mksc-item-row">
				<span class="mksc-drag-handle" title="<?php esc_attr_e( 'Drag to move', 'mk-sidebar-cleaner' ); ?>">&#x2630;</span>
				<?php if ( $has_children ) : ?>
				<button type="button"
				        class="mksc-toggle"
				        data-slug="<?= esc_attr( $item['slug'] ) ?>"
				        title="<?php esc_attr_e( 'Show/hide submenus', 'mk-sidebar-cleaner' ); ?>">&#x25B6;</button>
				<?php else : ?>
				<span class="mksc-toggle-spacer"></span>
				<?php endif; ?>
				<span class="mksc-item-pos-badge">[<?= esc_html( $item['pos'] ) ?>]</span>
				<span class="mksc-item-name" title="<?php esc_attr_e( 'Double-click to rename', 'mk-sidebar-cleaner' ); ?>"><?= esc_html( $display_name ) ?></span>
				<label class="mksc-hide-toggle" title="<?php esc_attr_e( 'Hide this item', 'mk-sidebar-cleaner' ); ?>">
					<input type="checkbox" class="mksc-hide-cb"<?= $item['is_hidden'] ? ' checked' : '' ?>>
					<span class="mksc-hide-icon">&#x1F6AB;</span>
				</label>
			</div>
			<?php if ( $has_children ) : ?>
			<ul class="mksc-subitems" hidden>
				<?php foreach ( $children as $child_name ) : ?>
				<li class="mksc-subitem"><?= esc_html( $child_name ) ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</li>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function menu_items(): array {
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) return [];
		$items = [];
		foreach ( $menu as $pos => $item ) {
			$slug = $item[2] ?? '';
			$name = wp_strip_all_tags( $item[0] ?? '' );
			if ( empty( $name ) || str_starts_with( $slug, 'separator' ) ) continue;

			$url = ( str_contains( $slug, '.php' ) || str_starts_with( $slug, 'http' ) )
				? admin_url( $slug )
				: admin_url( 'admin.php?page=' . $slug );

			// Attach first-level submenus as informational children (read-only in UI).
			$children = [];
			foreach ( (array) ( $submenu[ $slug ] ?? [] ) as $sub ) {
				$sub_name = wp_strip_all_tags( $sub[0] ?? '' );
				if ( ! empty( $sub_name ) ) {
					$children[] = $sub_name;
				}
			}

			$items[] = [
				'pos'      => (int) $pos,
				'name'     => $name,
				'slug'     => $slug,
				'url'      => $url,
				'children' => $children,
			];
		}
		usort( $items, fn( $a, $b ) => $a['pos'] <=> $b['pos'] );
		return $items;
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
		check_admin_referer( 'mk_sidebar_save' );

		$scope  = sanitize_key( $_POST['mk_scope'] ?? 'personal' );
		$config = $this->config->sanitize_from_post( $_POST );
		$this->config->save( $scope, $config );

		wp_safe_redirect( admin_url(
			'tools.php?page=' . MK_Sidebar_Cleaner_Config::PAGE_SLUG
			. '&tab=' . $scope
			. '&mk_saved=' . $scope
		) );
		exit;
	}

	public function handle_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
		check_admin_referer( 'mk_sidebar_save' );

		$scope = sanitize_key( $_GET['mk_scope'] ?? 'personal' );
		$this->config->reset( $scope );

		wp_safe_redirect( admin_url(
			'tools.php?page=' . MK_Sidebar_Cleaner_Config::PAGE_SLUG
			. '&tab=' . $scope
			. '&mk_saved=' . $scope
		) );
		exit;
	}

}
