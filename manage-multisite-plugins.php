<?php

/**
 * Plugin Name:   Manage Multisite Plugins
 * Description:   Provides thorough plugin usage data on the multisite network level.
 * Author:        Cornell SC Johnson College of Business
 * Author URI:    https://business.cornell.edu
 * Version:       1.0
 */

namespace Manage_Multisite_Plugins;

use DateTime;
use DateTimeZone;
use Exception;

final class Admin {

	const NETWORK_PLUGINS_DATA_MENU_SLUG = 'mmp-network-plugins';
	const DOWNLOAD_DATA_PLUGINS_ACTION = 'mmp_download_plugins';
	const DOWNLOAD_DATA_PLUGINS_NONCE = 'mmp_download_plugins_nonce';
	const DOWNLOAD_DATA_PLUGINS_USER_CAP = 'manage_options';

	/**
	 * Registers all of our hooks and what not.
	 */
	public static function register(): void {
		$plugin = new self();

		// Add internal plugin header.
		add_filter( 'extra_plugin_headers', [ $plugin, 'filter_extra_plugin_headers' ] );

		// Add network admin menu pages.
		add_action( 'network_admin_menu', [ $plugin, 'add_network_admin_menu_items' ] );

		// Process plugin data download.
		add_action( 'current_screen', [ $plugin, 'process_plugins_data_download' ] );
	}

	/**
	 * Allows you to define a custom plugin header
	 * that lets the system know it's an "internal" plugin.
	 *
	 * An internal plugin is a plugin you built yourself,
	 * as opposed to a third-party plugin.
	 *
	 * Can modify the default "Internal Plugin"
	 * header with the MMP_PLUGIN_HEADER_INTERNAL constant
	 * or "mmp_header_internal" filter.
	 *
	 * @return string
	 */
	public function get_internal_plugin_header(): string {
		$default = 'Internal Plugin';
		if ( defined( 'MMP_PLUGIN_HEADER_INTERNAL' ) ) {
			$default = MMP_PLUGIN_HEADER_INTERNAL;
		}
		$plugin_header = apply_filters( 'mmp_header_internal', $default );
		if ( ! empty( $plugin_header ) ) {
			return $plugin_header;
		}
		return $default;
	}

	/**
	 * This filter allows you to add our custom internal plugin header.
	 *
	 * @param array $headers
	 *
	 * @return array
	 */
	public function filter_extra_plugin_headers( array $headers ): array {
		$headers[] = $this->get_internal_plugin_header();
		return $headers;
	}

	private function get_network_plugins_page_url(): string {
		return network_admin_url( 'plugins.php' );
	}

	private function get_network_plugins_data_page_url(): string {
		return add_query_arg( 'page', self::NETWORK_PLUGINS_DATA_MENU_SLUG, $this->get_network_plugins_page_url() );
	}

	private function get_download_plugins_data_url(): string {
		return wp_nonce_url( $this->get_network_plugins_data_page_url(), self::DOWNLOAD_DATA_PLUGINS_ACTION, self::DOWNLOAD_DATA_PLUGINS_NONCE );
	}

	/**
	 * Get the site's timezone name from the options table.
	 *
	 * @return string
	 */
	private function get_site_timezone_name(): string {
		$name = get_option( 'timezone_string' );
		return empty( $name ) ? 'UTC' : $name;
	}

	/**
	 * Get the site's DateTimeZone.
	 *
	 * If the site's timezone string is invalid,
	 * will return the UTC timezone.
	 *
	 * @return DateTimeZone
	 */
	private function get_site_timezone(): DateTimeZone {
		try {
			return new DateTimeZone( $this->get_site_timezone_name() );
		} catch ( Exception $error ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	private function get_site_blog_ids(): array {
		global $wpdb;
		return $wpdb->get_col( "
                SELECT blog_id
                FROM {$wpdb->blogs}
                WHERE site_id = '{$wpdb->siteid}'
                AND spam = '0'
                AND deleted = '0'
                AND archived = '0'
            " );
	}

	private function get_site_blog_name( string $blog_id ): string {
		$blog_details = get_blog_details( [ 'blog_id' => $blog_id ] );
		if ( ! empty( $blog_details->blogname ) ) {
			return $blog_details->blogname;
		}
		return '';
	}

	private function is_internal_plugin( array $plugin_data ): bool {
		$internal_plugin_header = $this->get_internal_plugin_header();
		return ! empty( $plugin_data[ $internal_plugin_header ] ) && strtolower( $plugin_data[ $internal_plugin_header ] ) === 'yes';
	}

	/**
	 * Has to be public to be used in the add_action() call.
	 *
	 * @return void
	 */
	public function add_network_admin_menu_items(): void {
		add_submenu_page(
			'plugins.php',
			'Manage Multisite Plugins',
			'Manage Multisite Plugins',
			'manage_options',
			self::NETWORK_PLUGINS_DATA_MENU_SLUG,
			[ $this, 'print_network_plugins_page' ]
		);
	}

	public function process_plugins_data_download(): void {

		if ( empty( $_GET[ self::DOWNLOAD_DATA_PLUGINS_NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET[ self::DOWNLOAD_DATA_PLUGINS_NONCE ], self::DOWNLOAD_DATA_PLUGINS_ACTION ) ) {
			wp_die();
		}

		if ( ! current_user_can( self::DOWNLOAD_DATA_PLUGINS_USER_CAP ) ) {
			wp_die( 'You do not have permission to access this data.' );
		}

		$plugins_data = $this->get_network_plugins_data();

		if ( empty( $plugins_data ) ) {
			wp_die( 'There is no plugin data to download.' );
		}

		// Create temporary CSV file for the complete photo list.
		$download_data_filename = 'manage-multisite-plugins';

		// Add date/time stamp.
		try {

			$date_time = new DateTime( 'now', $this->get_site_timezone() );

			$download_data_filename .= '-' . $date_time->format( 'Y-m-d\TH-i-s' );

		} catch ( \Exception $error ) {
		}

		$download_data_filename  .= '.csv';
		$download_data_file_path = "/tmp/{$download_data_filename}";
		$download_data_file      = fopen( $download_data_file_path, 'w' );

		$data_headers = [
			'Name',
			'Internal',
			'Must-use',
			'Network-active',
			'Our version',
			'Current version',
			'Needs update',
			'Allow update',
			'Is forked;',
			'In WP repo',
			'Purchased',
			'Purchased Date',
			'Purchased: Expiration Date',
			'Flag for removal',
			'Author',
		];

		$blog_ids = $this->get_site_blog_ids();

		$blog_cols_by_id = [];

		foreach ( $blog_ids as $blog_id ) {
			$blog_name = $this->get_site_blog_name( $blog_id );
			if ( empty( $blog_name ) ) {
				continue;
			}
			$blog_cols_by_id[ $blog_id ] = $blog_name;
		}

		// Add blog names as data headers.
		$data_headers = array_merge( $data_headers, array_values( $blog_cols_by_id ) );

		fputcsv( $download_data_file, $data_headers );

		// Add each plugin row.
		foreach ( $plugins_data as $data ) {

			$is_internal       = $this->is_internal_plugin( $data );
			$is_must_use       = ! empty( $data[ 'IsMustUse' ] );
			$is_network_active = ! empty( $data[ 'IsNetworkActive' ] );

			$data_row = [
				$data[ 'Name' ],
				$is_internal ? "Yes" : "No",
				$is_must_use ? "Yes" : "No",
				$is_network_active ? "Yes" : "No",
				$data[ 'Version' ],
				'', // Current version
				'', // Needs update
				'', // Allow update
				'', // Is forked
				'', // In WP repo
				'', // Purchased
				'', // Purchased Date
				'', // Purchased: Expiration Date
				'', // Flag for removal
				$data[ 'AuthorName' ],
			];

			// Add a column for each blog.
			foreach ( $blog_cols_by_id as $blog_id => $blog_name ) {
				if ( $is_must_use || $is_network_active || ! empty( $data[ 'Sites' ][ $blog_id ] ) ) {
					$data_row[] = 'X';
				} else {
					$data_row[] = '';
				}
			}

			fputcsv( $download_data_file, $data_row );
		}

		// Close the file.
		fclose( $download_data_file );

		// Output headers so that the file is downloaded rather than displayed.
		header( 'Content-type: text/csv' );
		header( "Content-disposition: attachment; filename = {$download_data_filename}" );
		header( 'Content-Length: ' . filesize( $download_data_file_path ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $download_data_file_path );

		exit;
	}

	private function get_network_plugins_data(): array {

		$all_plugin_data = [];

		// Process must-use plugins.
		$must_use_plugins = get_mu_plugins();
		foreach ( $must_use_plugins as $plugin_key => $plugin_data ) {
			if ( ! empty( $all_plugin_data[ $plugin_key ] ) ) {
				continue;
			}

			$plugin_data[ 'IsNetworkActive' ] = false;
			$plugin_data[ 'IsMustUse' ]       = true;

			$all_plugin_data[ $plugin_key ] = $plugin_data;
		}

		// Process standard plugins.
		$standard_plugins = get_plugins();

		foreach ( $standard_plugins as $plugin_key => $plugin_data ) {
			if ( ! empty( $all_plugin_data[ $plugin_key ] ) ) {
				continue;
			}

			$plugin_data[ 'IsNetworkActive' ] = false;
			$plugin_data[ 'IsMustUse' ]       = false;

			$all_plugin_data[ $plugin_key ] = $plugin_data;
		}

		// Process network activated plugins.
		$network_active_plugins = get_site_option( 'active_sitewide_plugins' );

		foreach ( $network_active_plugins as $plugin_key => $value ) {
			if ( empty( $all_plugin_data[ $plugin_key ] ) ) {
				continue;
			}
			$all_plugin_data[ $plugin_key ][ 'IsNetworkActive' ] = true;
			$all_plugin_data[ $plugin_key ][ 'IsMustUse' ]       = false;
		}

		$blog_ids = $this->get_site_blog_ids();

		// Process site activated plugins.
		foreach ( $blog_ids as $blog_id ) {

			$site_active_plugins = get_blog_option( $blog_id, 'active_plugins' );
			if ( empty( $site_active_plugins ) ) {
				continue;
			}

			foreach ( $site_active_plugins as $plugin_key ) {

				if ( empty( $all_plugin_data[ $plugin_key ] ) ) {
					continue;
				}

				$this_plugin_data = &$all_plugin_data[ $plugin_key ];

				if ( empty( $this_plugin_data[ 'Sites' ] ) ) {
					$this_plugin_data[ 'Sites' ] = [];
				}

				$blog_name = $this->get_site_blog_name( $blog_id );

				if ( ! empty( $blog_name ) ) {
					$this_plugin_data[ 'Sites' ][ $blog_id ] = $blog_name;
				}
			}
		}

		uasort( $all_plugin_data, function ( $a, $b ) {
			return strnatcasecmp( $a[ 'Name' ], $b[ 'Name' ] );
		} );

		return $all_plugin_data;
	}

	private function process_plugin_counts( array $plugin_data ): array {

		$internal_count       = 0;
		$mu_count             = 0;
		$network_active_count = 0;
		$inactive_count       = 0;
		$active_count         = 0;

		foreach ( $plugin_data as $data ) {
			if ( $this->is_internal_plugin( $data ) ) {
				$internal_count++;
			}
			if ( ! empty( $data[ 'IsMustUse' ] ) ) {
				$mu_count++;
			} else if ( ! empty( $data[ 'IsNetworkActive' ] ) ) {
				$network_active_count++;
			} else if ( empty( $data[ 'Sites' ] ) ) {
				$inactive_count++;
			} else {
				$active_count++;
			}
		}

		return [
			'total'    => count( $plugin_data ),
			'internal' => $internal_count,
			'mu'       => $mu_count,
			'network'  => $network_active_count,
			'inactive' => $inactive_count,
			'active'   => $active_count,
		];
	}

	private function print_plugin_data_table( array $plugin_data, string $labelledby ): void {
		?>
        <table class="mmp-plugins" aria-labelledby="<?php echo esc_attr( $labelledby ); ?>">
            <thead>
            <tr>
                <th class="mmp-plugins-cell--name">Plugin</th>
                <th class="mmp-plugins-cell--internal">Internal</th>
                <th class="mmp-plugins-cell--must-use">Must-use</th>
                <th class="mmp-plugins-cell--network">Network active</th>
                <th class="mmp-plugins-cell--version">Version</th>
                <th class="mmp-plugins-cell--update">Has update</th>
                <th class="mmp-plugins-cell--sites">Sites</th>
            </tr>
            </thead>
            <tbody>
			<?php

			foreach ( $plugin_data as $plugin_path => $data ) :

				$is_internal = $this->is_internal_plugin( $data );
				$is_must_use = ! empty( $data[ 'IsMustUse' ] );
				$is_network_active = ! empty( $data[ 'IsNetworkActive' ] );

				$row_classes = [ 'mmp-plugins-row' ];

				if ( $is_internal ) {
					$row_classes[] = 'mmp-plugins-row--internal';
				}

				?>
                <tr class="<?php echo implode( ' ', $row_classes ); ?>">
                    <td class="mmp-plugins-cell--name">
                        <span class="mmp-plugins-meta">
                            <span class="mmp-plugins-name"><?php echo $data[ 'Name' ]; ?></span>
                            <span class="mmp-plugins-path"><strong>Path:</strong> <?php echo $plugin_path; ?></span>
                            <?php

                            if ( ! empty( $data[ 'AuthorName' ] ) ) :
	                            ?>
                                <span class="mmp-plugins-author">
                                    <strong>Author:</strong>
                                    <?php

                                    if ( ! empty( $data[ 'AuthorURI' ] ) ) :
	                                    ?>
                                        <a href="<?php echo esc_url( $data[ 'AuthorURI' ] ); ?>"><?php echo $data[ 'AuthorName' ]; ?></a>
                                    <?php
                                    else:
	                                    echo $data[ 'AuthorName' ];
                                    endif;

                                    ?>
                                </span>
                            <?php
                            endif;

                            ?>
                        </span>
						<?php

						if ( ! empty( $data[ 'Description' ] ) ) :
							?>
                            <span class="mmp-plugins-desc"><?php echo $data[ 'Description' ]; ?></span>
						<?php
						endif;

						?>
                    </td>
                    <td class="mmp-plugins-cell--internal"><?php echo $is_internal ? "Yes" : "No"; ?></td>
                    <td class="mmp-plugins-cell--must-use"><?php echo $is_must_use ? "Yes" : "No"; ?></td>
                    <td class="mmp-plugins-cell--network"><?php echo $is_network_active ? "Yes" : "No"; ?></td>
                    <td class="mmp-plugins-cell--version"><?php echo $data[ 'Version' ]; ?></td>
                    <td class="mmp-plugins-cell--update">TBD</td>
                    <td class="mmp-plugins-cell--sites">
						<?php

						if ( $is_must_use || $is_network_active ) :
							?>
                            <span class="mmp-plugins-sites"><em>Automatically active on all sites.</em></span>
						<?php
						endif;

						if ( ! empty( $data[ 'Sites' ] ) ) :
							?>
                            <span class="mmp-plugins-sites">Manually active on these sites:</span>
                            <ol>
								<?php

								foreach ( $data[ 'Sites' ] as $blog_id => $site ) :
									?>
                                    <li><a href="<?php echo esc_url( get_admin_url( $blog_id, 'plugins.php' ) ); ?>"><?php echo $site; ?></a></li>
								<?php
								endforeach;

								?>
                            </ol>
						<?php
						endif;

						?>
                    </td>
                </tr>
			<?php
			endforeach;

			?>
            </tbody>
        </table>
		<?php
	}

	/**
	 * Has to be public to be used in the add_submenu_page() call.
	 *
	 * @return void
	 */
	public function print_network_plugins_page(): void {
		?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php

			$plugins_page_url     = $this->get_network_plugins_page_url();
			$plugin_data          = $this->get_network_plugins_data();

			if ( empty( $plugin_data ) ) :
				?>
                <p>There is no plugin data. <a href="<?php echo esc_url( $plugins_page_url ); ?>">Go to main plugins page</a></p>
			<?php
			else:

				// Organize plugins into categories.
				$internal_plugins = [];
				$external_plugins = [];

				$must_use_plugins       = [];
				$network_active_plugins = [];
				$inactive_plugins       = [];
				$active_plugins         = [];

				foreach ( $plugin_data as $key => $data ) {
					if ( $this->is_internal_plugin( $data ) ) {
						$internal_plugins[ $key ] = $data;
					} else {
						$external_plugins[ $key ] = $data;
					}
					if ( ! empty( $data[ 'IsMustUse' ] ) ) {
						$must_use_plugins[ $key ] = $data;
					} else if ( ! empty( $data[ 'IsNetworkActive' ] ) ) {
						$network_active_plugins[ $key ] = $data;
					} else if ( empty( $data[ 'Sites' ] ) ) {
						$inactive_plugins[ $key ] = $data;
					} else {
						$active_plugins[ $key ] = $data;
					}
				}

				$all_plugins_count      = $this->process_plugin_counts( $plugin_data );
				$internal_plugins_count = $this->process_plugin_counts( $internal_plugins );
				$external_plugins_count = $this->process_plugin_counts( $external_plugins );

				?>
                <p>This page contains "extra" data about the plugins on our network. To manage plugins, <a href="<?php echo esc_url( $plugins_page_url ); ?>">go to main plugins page</a>.</p>
                <p><a class="button" href="<?php echo esc_url( $this->get_download_plugins_data_url() ); ?>">Download plugin data</a></p>
				<?php

				?>
                <h2 class="mmp-plugins-section-heading">Statistics</h2>
                <p>There are <?php echo $all_plugins_count[ 'total' ]; ?> plugins.</p>
                <div style="background-color:#fff;padding:0.5rem 1rem 1rem;">

                    <h3>All plugins</h3>
                    <ul style="margin:0 0 1.5rem 1.5rem;list-style:disc;">
                        <li><a href="#must-use"><?php echo $all_plugins_count[ 'mu' ]; ?> plugins are must-use</a></li>
                        <li><a href="#network-active"><?php echo $all_plugins_count[ 'network' ]; ?> plugins are network-active</a></li>
                        <li><a href="#inactive"><?php echo $all_plugins_count[ 'inactive' ]; ?> plugins are inactive</a></li>
                        <li><a href="#active"><?php echo $all_plugins_count[ 'active' ]; ?> plugins are manually active somewhere</a></li>
                    </ul>

                    <h3>Plugins by type</h3>
                    <div style="display:flex;gap:1rem;">
                        <div>
                            <h4 style="margin:0 0 1rem;"><?php echo $internal_plugins_count[ 'total' ]; ?> plugins are internal plugins</h4>
                            <ul style="margin:0.5rem 0 0.75rem 1.5rem;list-style:disc;">
                                <li><?php echo $internal_plugins_count[ 'mu' ]; ?> plugins are must-use</li>
                                <li><?php echo $internal_plugins_count[ 'network' ]; ?> plugins are network-active</li>
                                <li><?php echo $internal_plugins_count[ 'inactive' ]; ?> plugins are inactive</li>
                                <li><?php echo $internal_plugins_count[ 'active' ]; ?> plugins are manually active somewhere</li>
                            </ul>
                        </div>
                        <div>
                            <h4 style="margin:0 0 1rem;"><?php echo $external_plugins_count[ 'total' ]; ?> plugins are third-party plugins</h4>
                            <ul style="margin:0.5rem 0 0.75rem 1.5rem;list-style:disc;">
                                <li><?php echo $external_plugins_count[ 'mu' ]; ?> plugins are must-use</li>
                                <li><?php echo $external_plugins_count[ 'network' ]; ?> plugins are network-active</li>
                                <li><?php echo $external_plugins_count[ 'inactive' ]; ?> plugins are inactive</li>
                                <li><?php echo $external_plugins_count[ 'active' ]; ?> plugins are manually active somewhere</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <h2 id="must-use" class="mmp-plugins-section-heading">Must-use plugins (<?php echo count( $must_use_plugins ); ?>)</h2>
                <p>Must-use plugins (a.k.a. mu-plugins) are plugins installed in a special directory inside the content folder and which are automatically enabled on all sites on the network. <a href="https://wordpress.org/documentation/article/must-use-plugins/" target="_blank">Learn more about
                        must-use plugins</a></p>
				<?php

				if ( empty( $must_use_plugins ) ) :
					?>
                    <p>There are no must-use plugins.</p>
				<?php
				else :
					$this->print_plugin_data_table( $must_use_plugins, "must-use" );
				endif;

				?>
                <h2 id="network-active" class="mmp-plugins-section-heading">Network-active plugins (<?php echo count( $network_active_plugins ); ?>)</h2>
                <p>Network-active are plugins which are activated inside the network admin and activated on all sites on the network. <a href="<?php echo esc_url( $plugins_page_url ); ?>">Manage network-active plugins</a></p>
				<?php

				if ( empty( $network_active_plugins ) ) :
					?>
                    <p>There are no network-active plugins.</p>
				<?php
				else :
					$this->print_plugin_data_table( $network_active_plugins, "network-active" );
				endif;

				?>
                <h2 id="inactive" class="mmp-plugins-section-heading">Plugins that are not active anywhere (<?php echo count( $inactive_plugins ); ?>)</h2>
				<?php

				if ( empty( $inactive_plugins ) ) :
					?>
                    <p>All plugins are active somewhere.</p>
				<?php
				else :
					$this->print_plugin_data_table( $inactive_plugins, "inactive" );
				endif;

				?>
                <h2 id="active" class="mmp-plugins-section-heading">Plugins that are manually active somewhere (<?php echo count( $active_plugins ); ?>)</h2>
				<?php

				if ( empty( $active_plugins ) ) :
					?>
                    <p>There are no other plugins.</p>
				<?php
				else :
					$this->print_plugin_data_table( $active_plugins, "active" );
				endif;
			endif;

			?>
        </div>
        <style>
            .mmp-plugins-section-heading {
                font-size: 1.8em;
                margin: 2.5rem 0 1rem;
            }

            table.mmp-plugins {
                width: 100%;
                background-color: #fff;
                text-align: left;
                margin: 1.5rem 0;
                border-collapse: collapse;
                border-spacing: 0;
            }

            table.mmp-plugins th {
                padding: 0.5rem 1.5rem 0.5rem 1rem;
                vertical-align: center;
            }

            table.mmp-plugins td {
                border-top: 1px solid #aaa;
                padding: 1rem 1.5rem 1rem 1rem;
                vertical-align: top;
            }

            .mmp-plugins-cell--name {
                width: 40%;
            }

            .mmp-plugins-cell--network {
                width: 5%;
            }

            .mmp-plugins-cell--version {
                width: 5%;
            }

            tr.mmp-plugins-row--internal {
                background-color: #f0f6fc;
                border-left: 4px solid #72aee6;
            }

            .mmp-plugins-name {
                display: block;
                font-size: 1.2em;
                line-height: 2;
                font-weight: bold;
            }

            .mmp-plugins-meta {
                display: flex;
                flex-direction: column;
            }

            .mmp-plugins-desc {
                display: block;
                margin: 0.75rem 0 0;
            }

            .mmp-plugins ol {
                margin: 0 0 0 1rem;
            }

            .mmp-plugins-sites {
                display: block;
                margin: 0 0 0.5rem;
            }

            .mmp-plugins-sites + ol {
                margin-top: 0.5rem;
            }
        </style>
		<?php
	}
}

if ( is_admin() ) {
	Admin::register();
}