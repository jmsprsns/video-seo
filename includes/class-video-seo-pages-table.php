<?php
/**
 * Pages with videos: the main-content check and whether each page is in the
 * video sitemap.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Pages list.
 */
final class Video_SEO_Pages_Table extends WP_List_Table {

	/**
	 * Filters: view, search.
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
	 * Built videos per page, by post ID.
	 *
	 * @var array<int,array[]>
	 */
	private $videos = array();

	/**
	 * Pages an SEO plugin marks noindex.
	 *
	 * @var array<int,bool>
	 */
	private $noindex = array();

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
				'singular' => 'page',
				'plural'   => 'pages',
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
			'vseo_page'    => __( 'Page', 'video-seo' ),
			'vseo_videos'  => __( 'Videos', 'video-seo' ),
			'vseo_focus'   => __( 'Main content check', 'video-seo' ),
			'vseo_sitemap' => __( 'Video sitemap', 'video-seo' ),
		);
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'vseo_page';
	}

	/**
	 * Views.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = isset( $this->filters['view'] ) ? $this->filters['view'] : '';
		$views   = array(
			'all'     => array( '', __( 'All', 'video-seo' ), null ),
			'flagged' => array( 'flagged', __( 'Video may not be the focus', 'video-seo' ), $this->stats['flagged'] ),
		);
		$out     = array();

		foreach ( $views as $id => $view ) {
			$out[ $id ] = sprintf(
				'<a href="%1$s"%2$s>%3$s%4$s</a>',
				esc_url( Video_SEO_Admin::tab_url( 'pages', array_filter( array( 'view' => $view[0] ) ) ) ),
				$current === $view[0] ? ' class="current" aria-current="page"' : '',
				esc_html( $view[1] ),
				null === $view[2] ? '' : ' <span class="count">(' . esc_html( number_format_i18n( $view[2] ) ) . ')</span>'
			);
		}

		return $out;
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( Video_SEO_Videos_Table::PER_PAGE_OPTION, 20 );
		$result   = Video_SEO_DB::pages( $this->filters, $per_page, $this->get_pagenum() );
		$ids      = array_map( 'intval', wp_list_pluck( $result['rows'], 'ID' ) );

		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		$this->items   = array();
		$this->noindex = $ids ? Video_SEO_Sitemap::noindex_ids( $ids ) : array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post ) {
				$this->items[]       = $post;
				$this->videos[ $id ] = Video_SEO_Video::for_post( $post );
			}
		}

		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Message for an empty table.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( ! empty( $this->filters['view'] ) ) {
			esc_html_e( 'Every page passes the main content check.', 'video-seo' );
			return;
		}

		esc_html_e( 'No pages with videos yet.', 'video-seo' );
	}

	/**
	 * Page title, type and status.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected function column_vseo_page( $post ) {
		$title  = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'video-seo' );
		$type   = get_post_type_object( $post->post_type );
		$status = get_post_status_object( $post->post_status );
		$edit   = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : '';

		$out  = '<strong class="vseo-page-title">' . ( $edit ? '<a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</strong>';
		$out .= '<span class="vseo-muted vseo-page-meta">' . esc_html( $type ? $type->labels->singular_name : $post->post_type );

		if ( 'publish' !== $post->post_status && $status ) {
			$out .= '<span class="vseo-dot">·</span>' . esc_html( $status->label );
		}

		$out .= '</span>';

		$actions = array();

		if ( $edit ) {
			$actions['vseo_edit'] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'video-seo' ) . '</a>';
		}

		if ( is_post_publicly_viewable( $post ) ) {
			$actions['vseo_view'] = '<a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'video-seo' ) . '</a>';
		}

		return $out . $this->row_actions( $actions );
	}

	/**
	 * The page's videos.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected function column_vseo_videos( $post ) {
		$videos = isset( $this->videos[ $post->ID ] ) ? $this->videos[ $post->ID ] : array();
		$out    = '<div class="vseo-strip">';

		foreach ( array_slice( $videos, 0, 4 ) as $video ) {
			$tone = Video_SEO_Video::is_listed( $video ) ? '' : ' is-off';
			$out .= '<span class="vseo-strip-item' . $tone . '" title="' . esc_attr( $video['title'] ) . '">';
			$out .= '' !== $video['thumbnail'] ? '<img src="' . esc_url( $video['thumbnail'] ) . '" alt="" loading="lazy">' : Video_SEO_Admin::icon( 'film', 16 );
			$out .= '</span>';
		}

		if ( count( $videos ) > 4 ) {
			$out .= '<span class="vseo-strip-more">+' . esc_html( number_format_i18n( count( $videos ) - 4 ) ) . '</span>';
		}

		$out .= '</div>';

		$listed = count( array_filter( $videos, array( 'Video_SEO_Video', 'is_listed' ) ) );

		/* translators: 1: videos with schema, 2: videos on the page. */
		$out .= '<span class="vseo-muted">' . esc_html( sprintf( _n( '%1$s of %2$s video has schema', '%1$s of %2$s videos have schema', count( $videos ), 'video-seo' ), number_format_i18n( $listed ), number_format_i18n( count( $videos ) ) ) ) . '</span>';

		return $out;
	}

	/**
	 * The main content check.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected function column_vseo_focus( $post ) {
		$scan  = get_post_meta( $post->ID, Video_SEO_Scanner::SCAN_META, true );
		$check = Video_SEO_Video::focus_check( is_array( $scan ) ? $scan : array() );

		if ( ! $check['flag'] ) {
			$out = '<span class="vseo-pill is-good">' . Video_SEO_Admin::icon( 'circle-check', 13 ) . esc_html__( 'Video is the focus', 'video-seo' ) . '</span>';
		} else {
			$out = '<span class="vseo-pill is-warn">' . Video_SEO_Admin::icon( 'triangle-alert', 13 ) . esc_html__( 'May not be the focus', 'video-seo' ) . '</span>';

			foreach ( $check['reasons'] as $reason ) {
				$out .= '<span class="vseo-detail">' . esc_html( $reason ) . '</span>';
			}
		}

		foreach ( $check['notes'] as $note ) {
			$out .= '<span class="vseo-detail vseo-muted">' . esc_html( $note ) . '</span>';
		}

		return $out;
	}

	/**
	 * Whether the page is in the video sitemap, and why not.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected function column_vseo_sitemap( $post ) {
		$listed = array_filter( isset( $this->videos[ $post->ID ] ) ? $this->videos[ $post->ID ] : array(), array( 'Video_SEO_Video', 'is_listed' ) );
		$reason = '';

		if ( ! Video_SEO_Sitemap::enabled() ) {
			$reason = __( 'The sitemap is off', 'video-seo' );
		} elseif ( 'publish' !== $post->post_status ) {
			$reason = __( 'Not published', 'video-seo' );
		} elseif ( '' !== $post->post_password ) {
			$reason = __( 'Password protected', 'video-seo' );
		} elseif ( ! in_array( $post->post_type, Video_SEO_Sitemap::post_types(), true ) ) {
			$reason = __( 'This type has no public pages', 'video-seo' );
		} elseif ( isset( $this->noindex[ $post->ID ] ) ) {
			$reason = __( 'Set to noindex', 'video-seo' );
		} elseif ( ! $listed ) {
			$reason = __( 'No video with schema yet', 'video-seo' );
		}

		if ( '' === $reason ) {
			return '<span class="vseo-pill is-good">' . Video_SEO_Admin::icon( 'circle-check', 13 ) . esc_html__( 'Listed', 'video-seo' ) . '</span>';
		}

		return '<span class="vseo-pill is-muted">' . Video_SEO_Admin::icon( 'circle-minus', 13 ) . esc_html( $reason ) . '</span>';
	}
}
