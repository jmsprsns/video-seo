<?php
/**
 * Admin side: the Content Powered > Video SEO page (video report, pages,
 * sitemap, settings) and the Plugins-screen link.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class Video_SEO_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'video-seo';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'clapperboard';

	/**
	 * Capability needed for the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'videos'   => 'list-video',
		'pages'    => 'file-text',
		'sitemap'  => 'network',
		'settings' => 'settings',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Video_SEO_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The current tab's list table.
	 *
	 * @var WP_List_Table|null
	 */
	private $table = null;

	/**
	 * Counts for the report.
	 *
	 * @var array|null
	 */
	private $stats = null;

	/**
	 * Constructor.
	 *
	 * @param Video_SEO_Plugin $plugin Plugin instance.
	 */
	public function __construct( Video_SEO_Plugin $plugin ) {
		$this->plugin = $plugin;

		Video_SEO_Menu::init();
		Video_SEO_Feedback::init();

		require_once VIDEO_SEO_DIR . 'includes/class-video-seo-videos-table.php';
		require_once VIDEO_SEO_DIR . 'includes/class-video-seo-pages-table.php';

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_' . Video_SEO_Videos_Table::PER_PAGE_OPTION, array( $this, 'save_screen_option' ), 10, 3 );

		add_action( 'wp_ajax_video_seo_tick', array( $this, 'ajax_tick' ) );
		add_action( 'wp_ajax_video_seo_test_key', array( $this, 'ajax_test_key' ) );
		add_action( 'admin_post_video_seo_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_video_seo_rescan', array( $this, 'handle_rescan' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( VIDEO_SEO_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	// Helpers -------------------------------------------------------------------------

	/**
	 * A bundled icon.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Size in px.
	 * @param string $class Extra classes.
	 * @return string Inline SVG.
	 */
	public static function icon( $name, $size = 18, $class = '' ) {
		return Video_SEO_Icons::get( $name, $size, $class );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function tab_url( $tab = 'videos', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Link that fetches some videos' details again.
	 *
	 * @param int[] $ids Video IDs.
	 * @return string
	 */
	public static function refresh_url( array $ids ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'video_seo_refresh',
					'video'  => implode( ',', array_map( 'intval', $ids ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'video_seo_refresh'
		);
	}

	/**
	 * Show a message on the next page load.
	 *
	 * @param string $text Message.
	 * @param string $type success|error|warning|info.
	 * @return void
	 */
	private static function flash( $text, $type = 'success' ) {
		set_transient(
			'video_seo_flash_' . get_current_user_id(),
			array(
				'text' => $text,
				'type' => $type,
			),
			60
		);
	}

	/**
	 * Stop unless the user can use the page.
	 *
	 * @return void
	 */
	private static function check_user() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'video-seo' ), 403 );
		}
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'videos'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'videos';
	}

	/**
	 * Report filters from the query string.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters only read.
		$view     = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		return array(
			'view'     => in_array( $view, array( 'ready', 'incomplete', 'attention', 'pending', 'flagged' ), true ) ? $view : '',
			'provider' => in_array( $provider, array( 'youtube', 'vimeo', 'file' ), true ) ? $provider : '',
			'search'   => Video_SEO_Fetcher::cut( $search, 100 ),
		);
	}

	/**
	 * Counts for the report, once per request.
	 *
	 * @return array
	 */
	private function stats() {
		if ( null === $this->stats ) {
			$this->stats = Video_SEO_DB::stats();
		}

		return $this->stats;
	}

	// Menu, settings, assets ----------------------------------------------------------------

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url( 'settings' ) ), esc_html__( 'Settings', 'video-seo' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url( 'videos' ) ), esc_html__( 'Video report', 'video-seo' ) )
		);

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = self::icon( self::ICON, 17, 'video-seo-menu-icon' );
		$menu_title = $icon . '<span class="video-seo-menu-label">' . esc_html__( 'Video SEO', 'video-seo' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Video_SEO_Menu::SLUG,
			__( 'Video SEO', 'video-seo' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Before the page is drawn: run a bulk action, tidy the URL, set up the table.
	 *
	 * @return void
	 */
	public function load_page() {
		$tab = self::current_tab();

		if ( 'videos' === $tab ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The bulk action checks its nonce below; the rest only reads.
			$action = isset( $_GET['action'] ) && '-1' !== $_GET['action'] ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

			if ( '' === $action && isset( $_GET['action2'] ) && '-1' !== $_GET['action2'] ) {
				$action = sanitize_key( wp_unslash( $_GET['action2'] ) );
			}

			if ( 'vseo_refresh' === $action && ! empty( $_GET['video'] ) ) {
				check_admin_referer( 'bulk-videos' );
				self::check_user();

				$ids = array_map( 'absint', (array) wp_unslash( $_GET['video'] ) );
				wp_safe_redirect( self::refresh_url( $ids ) );
				exit;
			}

			// The filter form also sends the bulk nonce: drop it from the URL.
			if ( ! empty( $_GET['_wp_http_referer'] ) || ! empty( $_GET['_wpnonce'] ) || isset( $_GET['filter_action'] ) ) {
				$keep = array_filter( self::filters() );

				if ( isset( $_GET['orderby'], $_GET['order'] ) ) {
					$keep['orderby'] = sanitize_key( wp_unslash( $_GET['orderby'] ) );
					$keep['order']   = 'asc' === $_GET['order'] ? 'asc' : 'desc';
				}

				if ( isset( $keep['search'] ) ) {
					$keep['s'] = $keep['search'];
					unset( $keep['search'] );
				}

				wp_safe_redirect( self::tab_url( 'videos', $keep ) );
				exit;
			}
			// phpcs:enable
		}

		if ( in_array( $tab, array( 'videos', 'pages' ), true ) ) {
			add_screen_option(
				'per_page',
				array(
					'default' => 20,
					'option'  => Video_SEO_Videos_Table::PER_PAGE_OPTION,
				)
			);

			$this->table = 'videos' === $tab
				? new Video_SEO_Videos_Table( self::filters(), $this->stats() )
				: new Video_SEO_Pages_Table( self::filters(), $this->stats() );
		}
	}

	/**
	 * Save the rows-per-page screen option.
	 *
	 * @param mixed  $status Value to save, or false.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		if ( Video_SEO_Videos_Table::PER_PAGE_OPTION === $option ) {
			return max( 5, min( 200, absint( $value ) ) );
		}

		return $status;
	}

	/**
	 * Body class for the page, so its full-width header can line up.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $this->hook_suffix && $screen->id === $this->hook_suffix ) {
			$classes .= ' vseo-page';
		}

		return $classes;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="video-seo-menu-icon-style">'
			. '#adminmenu a:has(>.video-seo-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .video-seo-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .video-seo-menu-icon,#adminmenu a:hover .video-seo-menu-icon,#adminmenu a:focus .video-seo-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$state = Video_SEO_Scanner::get_state();

		wp_enqueue_style( 'video-seo-admin', VIDEO_SEO_URL . 'assets/admin.css', array(), VIDEO_SEO_VERSION );
		wp_enqueue_script( 'video-seo-admin', VIDEO_SEO_URL . 'assets/admin.js', array(), VIDEO_SEO_VERSION, true );

		wp_localize_script(
			'video-seo-admin',
			'video_seo_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'video_seo_admin' ),
				'working' => 'running' === $state['status'] || Video_SEO_DB::count_due() > 0,
				'text'    => array(
					'copied'   => __( 'Copied', 'video-seo' ),
					'testing'  => __( 'Testing…', 'video-seo' ),
					'failed'   => __( 'The test didn\'t finish. Try again.', 'video-seo' ),
					/* translators: 1: posts scanned, 2: posts in total. */
					'scanning' => __( 'Scanning your content: %1$s of %2$s', 'video-seo' ),
					/* translators: %s: number of videos. */
					'fetching' => __( 'Getting details for %s videos…', 'video-seo' ),
					'done'     => __( 'All done. Reloading…', 'video-seo' ),
				),
			)
		);
	}

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Video_SEO_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Video_SEO_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they're saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->plugin->get_settings();
		$types    = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$types    = array_values( array_intersect( $types, array_keys( Video_SEO_Plugin::available_post_types() ) ) );
		$all      = count( $types ) === count( Video_SEO_Plugin::available_post_types() );
		$facade   = isset( $input['facade'] ) ? sanitize_key( $input['facade'] ) : 'off';
		$key      = (string) $current['youtube_key'];
		$new_key  = isset( $input['youtube_key_new'] ) ? trim( sanitize_text_field( $input['youtube_key_new'] ) ) : '';

		if ( ! empty( $input['youtube_key_remove'] ) ) {
			$key = '';
		} elseif ( '' !== $new_key ) {
			if ( Video_SEO_Fetcher::is_youtube_key( $new_key ) ) {
				$key = $new_key;
			} else {
				add_settings_error( Video_SEO_Plugin::OPTION, 'video_seo_key', __( 'That doesn\'t look like a Google API key, so the saved key was kept.', 'video-seo' ) );
			}
		}

		if ( ! $types ) {
			add_settings_error( Video_SEO_Plugin::OPTION, 'video_seo_types', __( 'Choose at least one type of content to scan. Every type is scanned for now.', 'video-seo' ), 'warning' );
			$all = true;
		}

		return array(
			'youtube_key'       => $key,
			'post_types_all'    => $all ? 1 : 0,
			'post_types'        => $all ? array() : $types,
			'schema'            => empty( $input['schema'] ) ? 0 : 1,
			'key_moments'       => empty( $input['key_moments'] ) ? 0 : 1,
			'featured_fallback' => empty( $input['featured_fallback'] ) ? 0 : 1,
			'sitemap'           => empty( $input['sitemap'] ) ? 0 : 1,
			'robots'            => empty( $input['robots'] ) ? 0 : 1,
			'facade'            => in_array( $facade, Video_SEO_Plugin::FACADE_MODES, true ) ? $facade : 'off',
		);
	}

	// Page ------------------------------------------------------------------------------------

	/**
	 * Tab label.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_label( $tab ) {
		$labels = array(
			'videos'   => __( 'Videos', 'video-seo' ),
			'pages'    => __( 'Pages', 'video-seo' ),
			'sitemap'  => __( 'Video Sitemap', 'video-seo' ),
			'settings' => __( 'Settings', 'video-seo' ),
		);

		return isset( $labels[ $tab ] ) ? $labels[ $tab ] : $tab;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = self::current_tab();
		?>
		<div class="vseo-header">
			<div class="vseo-header-title">
				<span class="vseo-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="vseo-version"><?php echo esc_html( 'v' . VIDEO_SEO_VERSION ); ?></span>
			</div>
			<nav class="vseo-tabs" aria-label="<?php esc_attr_e( 'Sections', 'video-seo' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="vseo-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( self::tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap vseo-wrap">
			<hr class="wp-header-end">

			<?php
			$this->render_notices();

			switch ( $tab ) {
				case 'pages':
					$this->render_pages_tab();
					break;
				case 'sitemap':
					$this->render_sitemap_tab();
					break;
				case 'settings':
					$this->render_settings_tab();
					break;
				default:
					$this->render_videos_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Messages: settings saved, a flashed message, the API key's state.
	 *
	 * @return void
	 */
	private function render_notices() {
		// Pages outside the Settings menu have to show "Settings saved." themselves.
		settings_errors();

		$flash = get_transient( 'video_seo_flash_' . get_current_user_id() );

		if ( is_array( $flash ) && ! empty( $flash['text'] ) ) {
			delete_transient( 'video_seo_flash_' . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info' ),
				esc_html( $flash['text'] )
			);
		}

		$api = get_option( Video_SEO_Fetcher::API_STATUS_OPTION, array() );

		if ( '' !== Video_SEO_Fetcher::youtube_key() && is_array( $api ) && isset( $api['ok'] ) && ! $api['ok'] ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'YouTube API key problem:', 'video-seo' ),
				esc_html( $api['message'] ),
				esc_url( self::tab_url( 'settings' ) . '#vseo-youtube-key' ),
				esc_html__( 'Check the key', 'video-seo' )
			);
		}
	}

	/**
	 * Progress box while the first scan runs or details are being fetched.
	 *
	 * @return void
	 */
	private function render_progress() {
		$state   = Video_SEO_Scanner::get_state();
		$due     = Video_SEO_DB::count_due();
		$running = 'running' === $state['status'];

		if ( ! $running && ! $due ) {
			return;
		}

		$percent = $running && $state['total'] ? min( 100, round( $state['done'] / $state['total'] * 100 ) ) : 0;
		?>
		<div class="vseo-progress-box" id="vseo-progress">
			<span class="vseo-progress-icon"><?php echo self::icon( 'scan-search', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<div class="vseo-progress-body">
				<strong id="vseo-progress-text">
					<?php
					if ( $running ) {
						/* translators: 1: posts scanned, 2: posts in total. */
						echo esc_html( sprintf( __( 'Scanning your content: %1$s of %2$s', 'video-seo' ), number_format_i18n( $state['done'] ), number_format_i18n( $state['total'] ) ) );
					} else {
						/* translators: %s: number of videos. */
						echo esc_html( sprintf( __( 'Getting details for %s videos…', 'video-seo' ), number_format_i18n( $due ) ) );
					}
					?>
				</strong>
				<span class="vseo-progress"><span style="width:<?php echo esc_attr( $running ? $percent : 100 ); ?>%"<?php echo $running ? '' : ' class="is-indeterminate"'; ?>></span></span>
				<span class="vseo-muted"><?php esc_html_e( 'This runs in the background. Keeping this page open speeds it up.', 'video-seo' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Stat cards.
	 *
	 * @return void
	 */
	private function render_stats() {
		$stats = $this->stats();
		$cards = array(
			array( 'film', number_format_i18n( $stats['total'] ), __( 'Videos found', 'video-seo' ), '', self::tab_url( 'videos' ) ),
			array( 'braces', number_format_i18n( $stats['ready'] + $stats['incomplete'] ), __( 'With VideoObject schema', 'video-seo' ), 'is-good', self::tab_url( 'videos' ) ),
			array( 'circle-alert', number_format_i18n( $stats['incomplete'] ), __( 'Missing details', 'video-seo' ), 'is-warn', self::tab_url( 'videos', array( 'view' => 'incomplete' ) ) ),
			array( 'triangle-alert', number_format_i18n( $stats['attention'] ), __( 'Need attention', 'video-seo' ), 'is-bad', self::tab_url( 'videos', array( 'view' => 'attention' ) ) ),
			array( 'focus', number_format_i18n( $stats['flagged'] ), __( 'Pages where the video may not be the focus', 'video-seo' ), 'is-warn', self::tab_url( 'pages', array( 'view' => 'flagged' ) ) ),
		);
		?>
		<div class="vseo-stats">
			<?php foreach ( $cards as $card ) : ?>
				<a class="vseo-stat <?php echo esc_attr( $card[3] ); ?>" href="<?php echo esc_url( $card[4] ); ?>">
					<span class="vseo-stat-icon"><?php echo self::icon( $card[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="vseo-stat-num"><?php echo esc_html( $card[1] ); ?></span>
					<span class="vseo-stat-label"><?php echo esc_html( $card[2] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Nudge towards a YouTube API key while YouTube videos lack details.
	 *
	 * @return void
	 */
	private function render_key_nudge() {
		if ( '' !== Video_SEO_Fetcher::youtube_key() || ! $this->stats()['youtube'] ) {
			return;
		}
		?>
		<div class="vseo-callout">
			<span class="vseo-callout-icon"><?php echo self::icon( 'key-round', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<div>
				<strong><?php esc_html_e( 'Add a free YouTube API key for complete details', 'video-seo' ); ?></strong>
				<p><?php esc_html_e( 'Without a key, YouTube only shares a video\'s title and thumbnail. With one, Video SEO adds the real upload date, duration, description and key moments. It takes about five minutes and costs nothing.', 'video-seo' ); ?></p>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( self::tab_url( 'settings' ) . '#vseo-youtube-key' ); ?>"><?php esc_html_e( 'Add a key', 'video-seo' ); ?></a>
		</div>
		<?php
	}

	/**
	 * The video report.
	 *
	 * @return void
	 */
	private function render_videos_tab() {
		$this->render_progress();
		$this->render_key_nudge();
		$this->render_stats();
		?>
		<div class="vseo-report">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="tab" value="videos">
				<?php
				$filters = self::filters();

				if ( '' !== $filters['view'] ) {
					echo '<input type="hidden" name="view" value="' . esc_attr( $filters['view'] ) . '">';
				}

				$this->table->prepare_items();
				$this->table->views();
				$this->table->search_box( __( 'Search videos', 'video-seo' ), 'vseo-search' );
				$this->table->display();
				?>
			</form>
			<form class="vseo-rescan" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="video_seo_rescan">
				<?php wp_nonce_field( 'video_seo_rescan' ); ?>
				<span class="vseo-muted"><?php esc_html_e( 'Posts are checked for videos whenever they\'re saved.', 'video-seo' ); ?></span>
				<button type="submit" class="button button-small"><?php echo self::icon( 'refresh-cw', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Scan all content again', 'video-seo' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Pages with videos.
	 *
	 * @return void
	 */
	private function render_pages_tab() {
		$this->render_progress();
		?>
		<div class="vseo-callout is-plain">
			<span class="vseo-callout-icon"><?php echo self::icon( 'focus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<div>
				<strong><?php esc_html_e( 'Is the video the main thing on the page?', 'video-seo' ); ?></strong>
				<p><?php esc_html_e( 'Google only shows a video result (the Videos tab, video mode, or a thumbnail beside a regular result) when the video is the page\'s main content. A video placed under a long article usually isn\'t, even with perfect schema. This check flags pages where the video comes after a lot of text or sits in a very long article.', 'video-seo' ); ?></p>
			</div>
		</div>
		<div class="vseo-report">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="tab" value="pages">
				<?php
				$this->table->prepare_items();
				$this->table->views();
				$this->table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * The sitemap: address, what's in it, where it's listed.
	 *
	 * @return void
	 */
	private function render_sitemap_tab() {
		$enabled = Video_SEO_Sitemap::enabled();
		$url     = Video_SEO_Sitemap::url();
		$types   = Video_SEO_Sitemap::post_types();
		$pages   = Video_SEO_DB::sitemap_post_count( $types );
		$sample  = $enabled ? Video_SEO_Sitemap::entries( $types, 1, 8 ) : array();
		$public  = (bool) get_option( 'blog_public' );
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<?php if ( ! $public ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site asks search engines not to index it (Settings > Reading), so Google won\'t use the sitemap until that\'s turned off.', 'video-seo' ); ?></p></div>
					<?php endif; ?>

					<div class="postbox vseo-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'network', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Your video sitemap', 'video-seo' ); ?></h2>
						</div>
						<div class="inside">
							<?php if ( ! $enabled ) : ?>
								<p class="vseo-intro"><?php esc_html_e( 'The video sitemap is turned off.', 'video-seo' ); ?> <a href="<?php echo esc_url( self::tab_url( 'settings' ) ); ?>"><?php esc_html_e( 'Turn it on in Settings', 'video-seo' ); ?></a></p>
							<?php else : ?>
								<p class="vseo-intro"><?php esc_html_e( 'It lists every published page with a video, with each video\'s title, description, thumbnail, length and date. It updates by itself as videos are added or removed.', 'video-seo' ); ?></p>
								<div class="vseo-url">
									<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" id="vseo-sitemap-url" aria-label="<?php esc_attr_e( 'Video sitemap address', 'video-seo' ); ?>">
									<button type="button" class="button" data-vseo-copy="#vseo-sitemap-url"><?php echo self::icon( 'copy', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy', 'video-seo' ); ?></span></button>
									<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo self::icon( 'external-link', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Open', 'video-seo' ); ?></a>
								</div>
								<p class="vseo-muted">
									<?php
									/* translators: 1: number of pages, 2: date and time. */
									echo esc_html( sprintf( _n( '%1$s published page has videos. Last changed %2$s.', '%1$s published pages have videos. Last changed %2$s.', $pages, 'video-seo' ), number_format_i18n( $pages ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), Video_SEO_Sitemap::changed() ) ) );
									?>
								</p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( $enabled ) : ?>
						<div class="postbox vseo-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'list-video', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'What Google sees', 'video-seo' ); ?></h2>
							</div>
							<div class="inside">
								<?php if ( ! $sample ) : ?>
									<p class="vseo-empty"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Nothing to list yet. Published pages with videos appear here once their details are in.', 'video-seo' ); ?></p>
								<?php else : ?>
									<ul class="vseo-sample">
										<?php foreach ( $sample as $entry ) : ?>
											<?php foreach ( $entry['videos'] as $video ) : ?>
												<li>
													<span class="vseo-sample-thumb"><img src="<?php echo esc_url( $video['thumbnail'] ); ?>" alt="" loading="lazy"><?php if ( $video['duration'] ) : ?><span><?php echo esc_html( Video_SEO_Video::clock( $video['duration'] ) ); ?></span><?php endif; ?></span>
													<span class="vseo-sample-text">
														<strong><?php echo esc_html( $video['title'] ); ?></strong>
														<span class="vseo-sample-desc"><?php echo esc_html( Video_SEO_Fetcher::cut( $video['description'], 160 ) ); ?></span>
														<a href="<?php echo esc_url( get_permalink( $entry['post'] ) ); ?>" target="_blank" rel="noopener noreferrer" class="vseo-sample-page"><?php echo esc_html( preg_replace( '#^https?://#', '', get_permalink( $entry['post'] ) ) ); ?></a>
													</span>
												</li>
											<?php endforeach; ?>
										<?php endforeach; ?>
									</ul>
									<?php if ( $pages > count( $sample ) ) : ?>
										<p class="vseo-muted"><?php esc_html_e( 'Showing the first few pages. The sitemap lists them all.', 'video-seo' ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox vseo-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'search-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Submit it to Google', 'video-seo' ); ?></h2>
						</div>
						<div class="inside">
							<ol class="vseo-steps">
								<li><?php echo wp_kses( __( 'Open <a href="https://search.google.com/search-console/sitemaps" target="_blank" rel="noopener noreferrer">Sitemaps in Google Search Console</a> for this site.', 'video-seo' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></li>
								<li><?php esc_html_e( 'Paste the sitemap address and press Submit.', 'video-seo' ); ?></li>
								<li><?php esc_html_e( 'Check the Video pages report under Indexing over the next few days.', 'video-seo' ); ?></li>
							</ol>
						</div>
					</div>

					<div class="postbox vseo-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'map-pinned', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Also listed in', 'video-seo' ); ?></h2>
						</div>
						<div class="inside">
							<ul class="vseo-checklist">
								<?php foreach ( $this->sitemap_listings( $enabled, $public ) as $listing ) : ?>
									<li class="<?php echo $listing[1] ? 'is-on' : 'is-off'; ?>"><?php echo self::icon( $listing[1] ? 'circle-check' : 'circle-minus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $listing[0] ); ?></span></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * Where else the sitemap is announced.
	 *
	 * @param bool $enabled Sitemap on.
	 * @param bool $public  Site public.
	 * @return array[] {label, on}
	 */
	private function sitemap_listings( $enabled, $public ) {
		$out = array(
			array( __( 'robots.txt', 'video-seo' ), $enabled && $public && $this->plugin->get_setting( 'robots' ) ),
		);

		$seo = array(
			'Yoast SEO'       => defined( 'WPSEO_VERSION' ),
			'Rank Math'       => class_exists( 'RankMath' ),
			'All in One SEO'  => function_exists( 'aioseo' ),
			'SEOPress'        => defined( 'SEOPRESS_VERSION' ),
		);

		$found = false;

		foreach ( $seo as $name => $active ) {
			if ( $active ) {
				$found = true;
				/* translators: %s: SEO plugin name. */
				$out[] = array( sprintf( __( '%s\'s sitemap index', 'video-seo' ), $name ), $enabled );
			}
		}

		if ( ! $found ) {
			$out[] = array( __( 'An SEO plugin\'s sitemap index (none active)', 'video-seo' ), false );
		}

		return $out;
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$settings = $this->plugin->get_settings();
		$option   = Video_SEO_Plugin::OPTION;
		$key      = (string) $settings['youtube_key'];
		$api      = get_option( Video_SEO_Fetcher::API_STATUS_OPTION, array() );
		$scanned  = $this->plugin->post_types();
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<form action="options.php" method="post" id="vseo-settings">
						<?php settings_fields( self::PAGE ); ?>

						<div class="postbox vseo-box" id="vseo-youtube-key">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'simpleicons/youtube', 18, 'vseo-youtube-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'YouTube details', 'video-seo' ); ?></h2>
							</div>
							<div class="inside">
								<p class="vseo-intro"><?php esc_html_e( 'With a free YouTube Data API key, each YouTube video\'s schema gets its real upload date, duration, description, view count and key moments. Without one, YouTube only shares the title and thumbnail.', 'video-seo' ); ?></p>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="vseo-key-new"><?php esc_html_e( 'API key', 'video-seo' ); ?></label></th>
										<td>
											<?php if ( '' !== $key ) : ?>
												<p class="vseo-key-saved">
													<?php echo self::icon( 'key-round', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
													<?php
													/* translators: %s: last four characters of the key. */
													echo esc_html( sprintf( __( 'Saved key ending in %s', 'video-seo' ), substr( $key, -4 ) ) );
													?>
													<?php if ( is_array( $api ) && isset( $api['ok'] ) ) : ?>
														<span class="vseo-pill <?php echo $api['ok'] ? 'is-good' : 'is-bad'; ?>"><?php echo esc_html( $api['ok'] ? __( 'Working', 'video-seo' ) : __( 'Not working', 'video-seo' ) ); ?></span>
													<?php endif; ?>
												</p>
											<?php endif; ?>
											<div class="vseo-inline">
												<input type="text" class="regular-text code" id="vseo-key-new" name="<?php echo esc_attr( $option ); ?>[youtube_key_new]" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $key ? __( 'Paste a new key to replace it', 'video-seo' ) : 'AIza…' ); ?>">
												<button type="button" class="button" id="vseo-test-key"><?php echo self::icon( 'plug-zap', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Test key', 'video-seo' ); ?></button>
											</div>
											<p class="vseo-test-result" id="vseo-test-result" role="status" aria-live="polite"></p>
											<?php if ( '' !== $key ) : ?>
												<label class="vseo-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[youtube_key_remove]" value="1"> <span><?php esc_html_e( 'Remove the saved key', 'video-seo' ); ?></span></label>
											<?php endif; ?>
											<?php if ( is_array( $api ) && isset( $api['ok'] ) && ! $api['ok'] && '' !== $key ) : ?>
												<p class="vseo-error-text"><?php echo esc_html( $api['message'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Get a key', 'video-seo' ); ?></th>
										<td>
											<ol class="vseo-steps">
												<li><?php echo wp_kses( __( 'Open the <a href="https://console.cloud.google.com/apis/library/youtube.googleapis.com" target="_blank" rel="noopener noreferrer">YouTube Data API v3</a> page in Google Cloud (any Google account works) and press Enable. Create a project first if it asks.', 'video-seo' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></li>
												<li><?php echo wp_kses( __( 'Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Credentials</a>, choose Create credentials > API key, and copy it.', 'video-seo' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></li>
												<li><?php esc_html_e( 'Optional but wise: edit the key, restrict it to the YouTube Data API v3, and leave Application restrictions on None (website restrictions block server requests).', 'video-seo' ); ?></li>
											</ol>
											<p class="description"><?php esc_html_e( 'The free quota is 10,000 units a day. Video SEO uses one unit per 50 videos, so even big sites stay far below it.', 'video-seo' ); ?></p>
										</td>
									</tr>
								</table>
							</div>
						</div>

						<div class="postbox vseo-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'braces', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Schema and sitemap', 'video-seo' ); ?></h2>
							</div>
							<div class="inside">
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><?php esc_html_e( 'Video schema', 'video-seo' ); ?></th>
										<td>
											<?php $this->toggle( 'schema', __( 'Add VideoObject schema to pages with videos', 'video-seo' ), $settings ); ?>
											<p class="description"><?php esc_html_e( 'Joins the schema of Yoast SEO, Rank Math or All in One SEO when one is active, and fills in a video they already describe instead of repeating it.', 'video-seo' ); ?></p>
											<?php $this->toggle( 'key_moments', __( 'Key moments: turn chapter timestamps in descriptions into Clip markup', 'video-seo' ), $settings ); ?>
											<p class="description">
												<?php
												printf(
													/* translators: %s: query string, e.g. ?t=95. */
													esc_html__( 'Needs three or more lines like "0:00 Intro", starting at 0:00. Each moment links to the page with %s, which starts the video there.', 'video-seo' ),
													'<code>?t=seconds</code>'
												);
												?>
											</p>
											<?php $this->toggle( 'featured_fallback', __( 'Use the page\'s featured image for video files without a poster image', 'video-seo' ), $settings ); ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Video sitemap', 'video-seo' ); ?></th>
										<td>
											<?php $this->toggle( 'sitemap', __( 'Build a video sitemap', 'video-seo' ), $settings ); ?>
											<?php $this->toggle( 'robots', __( 'List it in robots.txt', 'video-seo' ), $settings ); ?>
											<p class="description">
												<?php
												printf(
													/* translators: %s: sitemap URL. */
													esc_html__( 'Address: %s', 'video-seo' ),
													'<a href="' . esc_url( Video_SEO_Sitemap::url() ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( Video_SEO_Sitemap::url() ) . '</code></a>'
												);
												?>
											</p>
										</td>
									</tr>
								</table>
							</div>
						</div>

						<div class="postbox vseo-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'zap', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Lightweight embeds', 'video-seo' ); ?></h2>
							</div>
							<div class="inside">
								<p class="vseo-intro"><?php esc_html_e( 'A YouTube or Vimeo player loads around 1 MB of scripts before anyone presses play. A click-to-play preview shows the thumbnail and a play button instead, and loads the player only when it\'s clicked.', 'video-seo' ); ?></p>
								<fieldset class="vseo-choices">
									<legend class="screen-reader-text"><?php esc_html_e( 'Lightweight embeds', 'video-seo' ); ?></legend>
									<?php
									$choices = array(
										'off'         => array( __( 'Off', 'video-seo' ), __( 'Every video loads its normal player.', 'video-seo' ) ),
										'except_main' => array( __( 'Every video except each page\'s main one (recommended)', 'video-seo' ), __( 'The first video on a page stays a real player, because Google needs to see a player to show a video result. The rest get the preview.', 'video-seo' ) ),
										'all'         => array( __( 'Every video', 'video-seo' ), __( 'Fastest pages, but Google may not count a video that only loads on a click, so pages can lose their video result.', 'video-seo' ) ),
									);

									foreach ( $choices as $value => $choice ) :
										?>
										<label class="vseo-choice">
											<input type="radio" name="<?php echo esc_attr( $option ); ?>[facade]" value="<?php echo esc_attr( $value ); ?>"<?php checked( $settings['facade'], $value ); ?>>
											<span><strong><?php echo esc_html( $choice[0] ); ?></strong><span class="description"><?php echo esc_html( $choice[1] ); ?></span></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</div>
						</div>

						<div class="postbox vseo-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'files', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Content to scan', 'video-seo' ); ?></h2>
							</div>
							<div class="inside">
								<p class="vseo-intro"><?php esc_html_e( 'Videos are found in the content and in page builders\' layouts (Elementor, Divi, Beaver Builder, Bricks, Oxygen, WPBakery and others). Changing this starts a fresh scan.', 'video-seo' ); ?></p>
								<fieldset class="vseo-types">
									<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'video-seo' ); ?></legend>
									<?php foreach ( Video_SEO_Plugin::available_post_types() as $type => $label ) : ?>
										<label class="vseo-check">
											<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>"<?php checked( in_array( $type, $scanned, true ) ); ?>>
											<span><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $type ); ?></code></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'With every type ticked, types added later by other plugins are included automatically.', 'video-seo' ); ?></p>
							</div>
						</div>
					</form>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox vseo-box vseo-save-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'save', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Save', 'video-seo' ); ?></h2>
						</div>
						<div class="inside">
							<p><?php esc_html_e( 'A new API key fetches every YouTube video\'s details again in the background.', 'video-seo' ); ?></p>
							<?php submit_button( __( 'Save Changes', 'video-seo' ), 'primary', 'submit', false, array( 'form' => 'vseo-settings' ) ); ?>
						</div>
					</div>

					<div class="postbox vseo-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'lightbulb', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Good to know', 'video-seo' ); ?></h2>
						</div>
						<div class="inside">
							<ul class="vseo-checklist">
								<li><?php echo self::icon( 'pencil', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Change a video\'s title, description, thumbnail or transcript on one page from the Video SEO panel in the editor\'s sidebar.', 'video-seo' ); ?></span></li>
								<li><?php echo self::icon( 'captions', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'A transcript goes into the schema, and can be shown under the video in a collapsible box.', 'video-seo' ); ?></span></li>
								<li><?php echo self::icon( 'link', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Only embedded videos count. A plain link to a video isn\'t a video on the page, so it gets no schema.', 'video-seo' ); ?></span></li>
								<li><?php echo self::icon( 'shield-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Private, deleted and embed-blocked videos are left out of the schema and the sitemap, and flagged in the report.', 'video-seo' ); ?></span></li>
							</ul>
						</div>
					</div>

					<?php $this->render_updates_card(); ?>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * A checkbox setting.
	 *
	 * @param string $name     Setting.
	 * @param string $label    Label.
	 * @param array  $settings Settings.
	 * @return void
	 */
	private function toggle( $name, $label, array $settings ) {
		printf(
			'<label class="vseo-check"><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> <span>%4$s</span></label>',
			esc_attr( Video_SEO_Plugin::OPTION ),
			esc_attr( $name ),
			checked( 1, (int) $settings[ $name ], false ),
			esc_html( $label )
		);
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_card() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( VIDEO_SEO_REPO ) );
		$check_url  = Video_SEO_Updater::check_now_url();
		?>
		<div class="postbox vseo-box vseo-updates-box">
			<div class="postbox-header">
				<h2 class="hndle"><?php echo self::icon( 'refresh-cw', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Updates', 'video-seo' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'video-seo' ), '<strong>' . esc_html( VIDEO_SEO_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Video_SEO_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'video-seo' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( VIDEO_SEO_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Video_SEO_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'video-seo' ),
							'<a href="' . esc_url( VIDEO_SEO_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>VIDEO_SEO_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'video-seo' ),
							'<a href="' . esc_url( VIDEO_SEO_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php echo self::icon( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check for updates', 'video-seo' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// Actions ----------------------------------------------------------------------------------

	/**
	 * AJAX: while the report is open, push the scan or the fetching along.
	 *
	 * @return void
	 */
	public function ajax_tick() {
		check_ajax_referer( 'video_seo_admin', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}

		$state = Video_SEO_Scanner::get_state();

		if ( 'running' === $state['status'] ) {
			$state = Video_SEO_Scanner::run_batch( 8.0 );
		} elseif ( Video_SEO_DB::count_due() > 0 ) {
			Video_SEO_Fetcher::process( 8 );
		}

		wp_send_json_success(
			array(
				'scanning' => 'running' === $state['status'],
				'done'     => (int) $state['done'],
				'total'    => (int) $state['total'],
				'due'      => Video_SEO_DB::count_due(),
			)
		);
	}

	/**
	 * AJAX: test a YouTube API key (the one typed in, else the saved one).
	 *
	 * @return void
	 */
	public function ajax_test_key() {
		check_ajax_referer( 'video_seo_admin', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'video-seo' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		$key = '' !== $key ? $key : Video_SEO_Fetcher::youtube_key();

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Paste a key first.', 'video-seo' ) ) );
		}

		$result = Video_SEO_Fetcher::test_key( $key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( Video_SEO_Fetcher::youtube_key() === $key ) {
			Video_SEO_Fetcher::set_api_status( true );
		}

		wp_send_json_success(
			array(
				'message' => Video_SEO_Fetcher::youtube_key() === $key
					? __( 'The key works.', 'video-seo' )
					: __( 'The key works. Save your changes to start using it.', 'video-seo' ),
			)
		);
	}

	/**
	 * Fetch some videos' details again (row action and bulk action).
	 *
	 * @return void
	 */
	public function handle_refresh() {
		check_admin_referer( 'video_seo_refresh' );
		self::check_user();

		$raw = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : '';
		$ids = array_slice( array_filter( array_map( 'absint', explode( ',', $raw ) ) ), 0, 200 );

		if ( $ids ) {
			Video_SEO_DB::mark_for_refresh( $ids );
			Video_SEO_Fetcher::fetch_rows( array_values( Video_SEO_DB::get_by_ids( array_slice( $ids, 0, 20 ) ) ), microtime( true ) + 15 );
			Video_SEO_Scanner::schedule_fetch();

			/* translators: %s: number of videos. */
			self::flash( sprintf( _n( 'Fetched the details of %s video again.', 'Fetched the details of %s videos again.', count( $ids ), 'video-seo' ), number_format_i18n( count( $ids ) ) ) );
		}

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : self::tab_url( 'videos' ) );
		exit;
	}

	/**
	 * Scan all content again.
	 *
	 * @return void
	 */
	public function handle_rescan() {
		check_admin_referer( 'video_seo_rescan' );
		self::check_user();

		Video_SEO_Scanner::start_full_scan();
		self::flash( __( 'Scanning all content again. The report updates as it goes.', 'video-seo' ), 'info' );

		wp_safe_redirect( self::tab_url( 'videos' ) );
		exit;
	}
}
