<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages reading, writing, and sanitizing plugin configuration.
 *
 * Config schema:
 * {
 *   hidden:        string[]          — slugs to remove from the sidebar
 *   moved:         { [slug]: target} — top-level slug → parent slug to nest under
 *   renamed:       { [slug]: name}   — slug → custom display name
 *   custom_groups: { slug, name, icon, position }[]
 *   updated:       int               — unix timestamp
 * }
 */
class MK_Sidebar_Cleaner_Config {

	const SUPERADMIN     = 'fedcon-adm';
	const PAGE_SLUG      = 'mk-sidebar-cleaner';
	const OPTION_DEFAULT = 'mk_sidebar_cleaner_default';
	const META_PERSONAL  = 'mk_sidebar_cleaner_config';

	/**
	 * Returns the config that should be applied for the current user:
	 *  - admin       → personal config if set, otherwise default
	 *  - other users → default config
	 * Superadmin is treated identically to any other admin.
	 */
	public function get_active(): ?array {
		if ( current_user_can( 'manage_options' ) ) {
			$personal = get_user_meta( get_current_user_id(), self::META_PERSONAL, true );
			if ( ! empty( $personal ) && is_array( $personal ) ) return $personal;
		}

		$default = get_option( self::OPTION_DEFAULT, [] );
		return ( ! empty( $default ) && is_array( $default ) ) ? $default : null;
	}

	public function get_personal(): array {
		$val = get_user_meta( get_current_user_id(), self::META_PERSONAL, true );
		return is_array( $val ) ? $val : [];
	}

	public function get_default(): array {
		$val = get_option( self::OPTION_DEFAULT, [] );
		return is_array( $val ) ? $val : [];
	}

	public function save( string $scope, array $config ): void {
		$config['updated'] = time();
		if ( $scope === 'default' ) {
			update_option( self::OPTION_DEFAULT, $config, false );
		} else {
			update_user_meta( get_current_user_id(), self::META_PERSONAL, $config );
		}
	}

	public function reset( string $scope ): void {
		if ( $scope === 'default' ) {
			delete_option( self::OPTION_DEFAULT );
		} else {
			delete_user_meta( get_current_user_id(), self::META_PERSONAL );
		}
	}

	/**
	 * Sanitizes raw $_POST data (expects a JSON-encoded 'mksc_state' field)
	 * into a clean config array ready to be saved.
	 */
	public function sanitize_from_post( array $post ): array {
		$raw = json_decode( wp_unslash( $post['mksc_state'] ?? '{}' ), true );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$hidden = [];
		foreach ( (array) ( $raw['hidden'] ?? [] ) as $slug ) {
			$hidden[] = sanitize_text_field( $slug );
		}

		$moved = [];
		foreach ( (array) ( $raw['moved'] ?? [] ) as $src => $tgt ) {
			$moved[ sanitize_text_field( $src ) ] = sanitize_text_field( $tgt );
		}

		$custom_groups = [];
		foreach ( (array) ( $raw['custom_groups'] ?? [] ) as $g ) {
			if ( empty( $g['slug'] ) || empty( $g['name'] ) ) continue;
			$custom_groups[] = [
				'slug'     => sanitize_key( $g['slug'] ),
				'name'     => sanitize_text_field( $g['name'] ),
				'icon'     => sanitize_text_field( $g['icon'] ?? 'dashicons-category' ),
				'position' => absint( $g['position'] ?? 30 ),
			];
		}

		$renamed = [];
		foreach ( (array) ( $raw['renamed'] ?? [] ) as $slug => $name ) {
			$renamed[ sanitize_text_field( $slug ) ] = sanitize_text_field( $name );
		}

		// order: zone_target → ordered slug list.
		// '__main__' is the JS key for the main sidebar (empty string target).
		$order = [];
		foreach ( (array) ( $raw['order'] ?? [] ) as $zone_key => $slugs ) {
			$zone_key = sanitize_text_field( $zone_key );
			$order[ $zone_key ] = array_values(
				array_map( 'sanitize_text_field', (array) $slugs )
			);
		}

		return [
			'hidden'        => array_values( array_unique( $hidden ) ),
			'moved'         => $moved,
			'renamed'       => $renamed,
			'custom_groups' => $custom_groups,
			'order'         => $order,
		];
	}
}
