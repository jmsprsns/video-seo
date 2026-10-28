<?php
/**
 * The video report: one row per video, with the pages it's on, its details
 * and its schema status.
 *
 * Column and row action keys are prefixed: WordPress styles some generic
 * ones on every admin page (a ".column-posts" cell is centered, an
 * ".approve" link is hidden).
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Videos list.
 */
final class Video_SEO_Videos_Table extends WP_List_Table {

	/**
	 * Screen option holding rows per page.
	 */
	const PER_PAGE_OPTION = 'video_seo_per_page';

	/**
	 * Report filters: view, provider, search.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Counts for the views.
	 *
	 * @var array<string,int>
	 */
	private $stats;

	/**
	 * Pages each listed video is on.
	 *
	 * @var array<int,array>
	 */
	private $posts = array();

	/**
	 * Constructor.
	 *
	 * @param array $filters Filters.
	 * @param array $stats   Counts from {@see Video_SEO_DB::stats()}.
	 */
	public function __construct( array $filters, array $stats ) {
		$this->filters = $filters;
		$this->stats   = $stats;

		parent::__construct(
			array(
				'singular' => 'video',
				'plural'   => 'videos',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox">',
			'vseo_video'    => __( 'Video', 'video-seo' ),
			'vseo_pages'    => __( 'Found on', 'video-seo' ),
			'vseo_length'   => __( 'Length', 'video-seo' ),
			'vseo_uploaded' => __( 'Uploaded', 'video-seo' ),
			'vseo_schema'   => __( 'Schema', 'video-seo' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'vseo_video'    => array( 'title', false ),
			'vseo_pages'    => array( 'posts', true ),
			'vseo_length'   => array( 'duration', true ),
			'vseo_uploaded' => array( 'upload_date', true ),
		);
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'vseo_video';
	}

	/**
	 * Views above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$views   = array(
			''           => array( __( 'All', 'video-seo' ), $this->stats['total'] ),
			'ready'      => array( __( 'Ready', 'video-seo' ), $this->stats['ready'] ),
			'incomplete' => array( __( 'Missing details', 'video-seo' ), $this->stats['incomplete'] ),
			'attention'  => array( __( 'Needs attention', 'video-seo' ), $this->stats['attention'] ),
			'pending'    => array( __( 'Getting details', 'video-seo' ), $this->stats['pending'] ),
		);
		$current = isset( $this->filters['view'] ) ? $this->filters['view'] : '';
		$out     = array();

		foreach ( $views as $view => $info ) {
			if ( '' !== $view && ! $info[1] && $current !== $view ) {
				continue;
			}

			$out[ '' === $view ? 'all' : $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Video_SEO_Admin::tab_url( 'videos', array_filter( array( 'view' => $view ) ) ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $info[0] ),
				esc_html( number_format_i18n( $info[1] ) )
			);
		}

		return $out;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'vseo_refresh' => __( 'Fetch details again', 'video-seo' ),
		);
	}

	/**
	 * Provider filter.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current = isset( $this->filters['provider'] ) ? $this->filters['provider'] : '';
		?>
		<div class="alignleft actions vseo-filters">
			<label class="screen-reader-text" for="vseo-provider"><?php esc_html_e( 'Filter by source', 'video-seo' ); ?></label>
			<select name="provider" id="vseo-provider">
				<option value=""><?php esc_html_e( 'All sources', 'video-seo' ); ?></option>
				<?php foreach ( array( 'youtube', 'vimeo', 'file' ) as $provider ) : ?>
					<option value="<?php echo esc_attr( $provider ); ?>"<?php selected( $current, $provider ); ?>>
						<?php echo esc_html( Video_SEO_Video::provider_label( $provider ) . ' (' . number_format_i18n( $this->stats[ $provider ] ) . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'video-seo' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$page     = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Sorting only.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		// phpcs:enable

		if ( 'title' === $orderby && ! isset( $_GET['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sorting only.
			$order = 'asc';
		}

		$total       = Video_SEO_DB::report_count( $this->filters );
		$this->items = Video_SEO_DB::report( $this->filters, $orderby, $order, $per_page, $page );
		$this->posts = Video_SEO_DB::posts_for_videos( wp_list_pluck( $this->items, 'id' ) );

		$post_ids = array();

		foreach ( $this->posts as $info ) {
			$post_ids = array_merge( $post_ids, $info['posts'] );
		}

		if ( $post_ids ) {
			_prime_post_caches( array_unique( $post_ids ), false, false );
		}

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_default_primary_column_name() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Message for an empty table.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( ! empty( $this->filters['search'] ) || ! empty( $this->filters['view'] ) || ! empty( $this->filters['provider'] ) ) {
			esc_html_e( 'No videos match these filters.', 'video-seo' );
			return;
		}

		esc_html_e( 'No videos found yet. Embed a YouTube or Vimeo video, or add a Video block, and it shows up here.', 'video-seo' );
	}

	/**
	 * Checkbox.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="vseo-cb-%1$d">%2$s</label><input type="checkbox" id="vseo-cb-%1$d" name="video[]" value="%1$d">',
			(int) $item['id'],
			esc_html( sprintf( /* translators: %s: video title. */ __( 'Select %s', 'video-seo' ), $this->title( $item ) ) )
		);
	}

	/**
	 * A row's title.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	private function title( array $item ) {
		if ( '' !== (string) $item['title'] ) {
			return $item['title'];
		}

		if ( 'file' === $item['provider'] ) {
			return wp_basename( (string) wp_parse_url( $item['url'], PHP_URL_PATH ) );
		}

		/* translators: 1: YouTube or Vimeo, 2: video ID. */
		return sprintf( __( '%1$s video %2$s', 'video-seo' ), Video_SEO_Video::provider_label( $item['provider'] ), $item['provider_id'] );
	}

	/**
	 * Video: thumbnail, title, source.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_vseo_video( $item ) {
		$thumb = (string) $item['thumbnail'];

		if ( '' === $thumb && 'youtube' === $item['provider'] && 'unavailable' !== $item['status'] ) {
			$thumb = 'https://i.ytimg.com/vi/' . $item['provider_id'] . '/mqdefault.jpg';
		}

		$icon = array(
			'youtube' => 'simpleicons/youtube',
			'vimeo'   => 'simpleicons/vimeo',
			'file'    => 'file-play',
		);

		$out  = '<div class="vseo-video">';
		$out .= '<span class="vseo-video-thumb">' . ( '' !== $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="" loading="lazy">' : Video_SEO_Admin::icon( 'film', 20 ) );

		if ( $item['duration'] ) {
			$out .= '<span class="vseo-video-length">' . esc_html( Video_SEO_Video::clock( $item['duration'] ) ) . '</span>';
		}

		$out .= '</span><span class="vseo-video-text">';
		$out .= '<strong class="vseo-video-title">' . esc_html( $this->title( $item ) ) . '</strong>';
		$out .= '<span class="vseo-video-source vseo-source--' . esc_attr( $item['provider'] ) . '">' . Video_SEO_Admin::icon( $icon[ $item['provider'] ], 13 ) . esc_html( Video_SEO_Video::provider_label( $item['provider'] ) );

		if ( ! empty( $item['extra']['channel'] ) ) {
			$out .= '<span class="vseo-dot">·</span>' . esc_html( $item['extra']['channel'] );
		}

		$out .= '</span></span></div>';

		$actions = array(
			'vseo_refresh' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Video_SEO_Admin::refresh_url( array( (int) $item['id'] ) ) ),
				esc_html__( 'Fetch details again', 'video-seo' )
			),
		);

		$actions['vseo_open'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $item['url'] ),
			'file' === $item['provider']
				? esc_html__( 'Open file', 'video-seo' )
				/* translators: %s: YouTube or Vimeo. */
				: esc_html( sprintf( __( 'Open on %s', 'video-seo' ), Video_SEO_Video::provider_label( $item['provider'] ) ) )
		);

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Pages the video is on.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_vseo_pages( $item ) {
		$info = isset( $this->posts[ $item['id'] ] ) ? $this->posts[ $item['id'] ] : array(
			'posts' => array(),
			'total' => 0,
		);

		if ( ! $info['posts'] ) {
			return '<span class="vseo-muted">—</span>';
		}

		$out = '<ul class="vseo-pages">';

		foreach ( $info['posts'] as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'video-seo' );
			$link  = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : get_permalink( $post );
			$state = 'publish' === $post->post_status ? '' : ' <span class="vseo-state">' . esc_html( $this->status_label( $post->post_status ) ) . '</span>';
			$flag  = get_post_meta( $post->ID, Video_SEO_Scanner::FLAG_META, true ) ? '<span class="vseo-flag" title="' . esc_attr__( 'The video may not be this page\'s main content', 'video-seo' ) . '">' . Video_SEO_Admin::icon( 'triangle-alert', 13 ) . '</span>' : '';

			$out .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' . $state . $flag . '</li>';
		}

		$out .= '</ul>';

		if ( $info['total'] > count( $info['posts'] ) ) {
			/* translators: %s: number of further pages. */
			$out .= '<span class="vseo-muted">' . esc_html( sprintf( __( 'and %s more', 'video-seo' ), number_format_i18n( $info['total'] - count( $info['posts'] ) ) ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * Post status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
		$object = get_post_status_object( $status );

		return $object ? $object->label : $status;
	}

	/**
	 * Length.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_vseo_length( $item ) {
		return $item['duration'] ? esc_html( Video_SEO_Video::clock( $item['duration'] ) ) : '<span class="vseo-muted">—</span>';
	}

	/**
	 * Upload date.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_vseo_uploaded( $item ) {
		if ( empty( $item['upload_date'] ) ) {
			return '<span class="vseo-muted">—</span>';
		}

		return esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( $item['upload_date'] ) ) );
	}

	/**
	 * Schema status and what's missing.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_vseo_schema( $item ) {
		$state  = $item['state'];
		$pills  = array(
			'ready'       => array( 'is-good', 'circle-check', __( 'Ready', 'video-seo' ) ),
			'incomplete'  => array( 'is-warn', 'circle-alert', __( 'Missing details', 'video-seo' ) ),
			'pending'     => array( 'is-info', 'hourglass', __( 'Getting details', 'video-seo' ) ),
			'error'       => array( 'is-bad', 'triangle-alert', __( 'Couldn\'t get details', 'video-seo' ) ),
			'unavailable' => array( 'is-bad', 'eye-off', __( 'Unavailable', 'video-seo' ) ),
		);
		$pill   = isset( $pills[ $state ] ) ? $pills[ $state ] : $pills['pending'];
		$out    = '<span class="vseo-pill ' . esc_attr( $pill[0] ) . '">' . Video_SEO_Admin::icon( $pill[1], 13 ) . esc_html( $pill[2] ) . '</span>';
		$fields = array_filter( explode( ',', (string) $item['missing'] ) );

		if ( 'incomplete' === $state && $fields ) {
			$labels = array();

			foreach ( $fields as $field ) {
				$labels[] = Video_SEO_Video::missing_label( $field );
			}

			/* translators: %s: list of missing details. */
			$out .= '<span class="vseo-detail">' . esc_html( sprintf( __( 'No %s', 'video-seo' ), wp_sprintf( '%l', $labels ) ) ) . '</span>';

			if ( 'youtube' === $item['provider'] && 'oembed' === $item['source'] && '' === Video_SEO_Fetcher::youtube_key() ) {
				$out .= '<a class="vseo-detail" href="' . esc_url( Video_SEO_Admin::tab_url( 'settings' ) ) . '#vseo-youtube-key">' . esc_html__( 'Add a YouTube API key to fill these in', 'video-seo' ) . '</a>';
			} elseif ( 'file' === $item['provider'] && in_array( 'thumbnail', $fields, true ) ) {
				$out .= '<span class="vseo-detail">' . esc_html__( 'Uses the page\'s featured image, or set a poster image.', 'video-seo' ) . '</span>';
			}
		} elseif ( in_array( $state, array( 'error', 'unavailable' ), true ) && '' !== (string) $item['error'] ) {
			$out .= '<span class="vseo-detail">' . esc_html( $item['error'] ) . '</span>';
		} elseif ( 'error' === $item['status'] && '' !== (string) $item['error'] ) {
			/* translators: %s: error message. */
			$out .= '<span class="vseo-detail">' . esc_html( sprintf( __( 'Last refresh failed: %s', 'video-seo' ), $item['error'] ) ) . '</span>';
		}

		return $out;
	}
}
