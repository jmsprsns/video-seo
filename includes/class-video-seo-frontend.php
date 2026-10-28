<?php
/**
 * Front end: lightweight click-to-play embeds, transcripts on the page, and
 * starting a video at a key moment (?t=seconds links from search results).
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end output.
 */
final class Video_SEO_Frontend {

	/**
	 * Built videos for the page being viewed, keyed by video key.
	 *
	 * @var array<string,array>|null
	 */
	private static $page_videos = null;

	/**
	 * Transcripts placed in the content being rendered, by video key.
	 *
	 * @var array<string,bool>
	 */
	private static $placed = array();

	/**
	 * Whether the content being rendered has the facade/transcript styles.
	 *
	 * @var bool
	 */
	private static $css_done = false;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 99 );
	}

	/**
	 * Every video on the page being viewed (any status), built, in page order.
	 *
	 * @return array<string,array>
	 */
	public static function page_videos() {
		if ( null !== self::$page_videos ) {
			return self::$page_videos;
		}

		self::$page_videos = array();

		if ( is_admin() || is_feed() || ! is_singular() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return self::$page_videos;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || post_password_required( $post ) || ! Video_SEO_Scanner::is_scannable( $post ) || ! is_post_publicly_viewable( $post ) ) {
			return self::$page_videos;
		}

		if ( ! Video_SEO_Scanner::maybe_has_videos( $post ) ) {
			return self::$page_videos;
		}

		Video_SEO_Scanner::ensure_scanned( $post );

		foreach ( Video_SEO_Video::for_post( $post ) as $video ) {
			self::$page_videos[ $video['key'] ] = $video;
		}

		return self::$page_videos;
	}

	/**
	 * Whether content is being rendered for an excerpt (or a meta
	 * description built from one), where nothing should be added.
	 *
	 * @return bool
	 */
	private static function is_excerpt() {
		return doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' );
	}

	/**
	 * Whether the content being filtered is the main post of a single page.
	 *
	 * @return bool
	 */
	private static function is_main_content() {
		if ( is_admin() || is_feed() || ! is_singular() || self::is_excerpt() ) {
			return false;
		}

		$post = get_post();

		return $post && (int) $post->ID === (int) get_queried_object_id();
	}

	/**
	 * Place a transcript right after the Embed or Video block it belongs to.
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public static function render_block( $html, $block ) {
		if ( empty( $block['blockName'] ) || ! in_array( $block['blockName'], array( 'core/embed', 'core/video', 'core-embed/youtube', 'core-embed/vimeo' ), true ) || ! self::is_main_content() ) {
			return $html;
		}

		$videos = self::page_videos();

		if ( ! $videos ) {
			return $html;
		}

		$video = null;

		if ( ! empty( $block['attrs']['url'] ) && is_string( $block['attrs']['url'] ) ) {
			$video = Video_SEO_Detector::from_url( $block['attrs']['url'] );
		} elseif ( preg_match( '#<video\b[^>]*\ssrc="([^"]+)"#i', $html, $m ) ) {
			$video = Video_SEO_Detector::from_url( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( ! $video || empty( $videos[ $video['key'] ] ) ) {
			return $html;
		}

		return $html . self::transcript( $videos[ $video['key'] ] );
	}

	/**
	 * Swap players for click-to-play previews, mark each video's position for
	 * key-moment links, and add transcripts not placed yet.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( is_admin() || is_feed() || is_embed() || self::is_excerpt() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_string( $content ) ) {
			return $content;
		}

		$main   = self::is_main_content();
		$videos = $main ? self::page_videos() : array();
		$mode   = (string) Video_SEO_Plugin::instance()->get_setting( 'facade' );
		$facade = in_array( $mode, array( 'except_main', 'all' ), true );
		$script = false;

		if ( ( $facade || $videos ) && preg_match( '/<iframe\b|<video\b/i', $content ) ) {
			$positions = array_flip( array_keys( $videos ) );
			$rows      = $facade && ! $main ? self::rows_in( $content ) : array();

			$content = preg_replace_callback(
				'#<iframe\b[^>]*>.*?</iframe>|<video\b[^>]*>#is',
				function ( $m ) use ( $videos, $positions, $rows, $mode, $facade, $main, &$script ) {
					$tag = $m[0];

					if ( 0 === stripos( $tag, '<video' ) ) {
						if ( ! preg_match( '#\ssrc="([^"]+)"#i', $tag, $src ) ) {
							return $tag;
						}

						$found = Video_SEO_Detector::from_url( html_entity_decode( $src[1], ENT_QUOTES, 'UTF-8' ) );

						return $found && isset( $positions[ $found['key'] ] ) ? self::mark( $tag, $positions[ $found['key'] ] ) : $tag;
					}

					if ( ! preg_match( '#<iframe\b[^>]*?\ssrc="([^"]+)"#i', $tag, $src ) ) {
						return $tag;
					}

					$found = Video_SEO_Detector::from_url( html_entity_decode( $src[1], ENT_QUOTES, 'UTF-8' ) );

					if ( ! $found || 'file' === $found['provider'] ) {
						return $tag;
					}

					$index = isset( $positions[ $found['key'] ] ) ? (int) $positions[ $found['key'] ] : -1;

					// The page's main video stays a real player, so Google sees it.
					$skip = 'except_main' === $mode && $main && 0 === $index;

					if ( $facade && ! $skip ) {
						if ( isset( $videos[ $found['key'] ] ) ) {
							$thumb = $videos[ $found['key'] ]['thumbnail'];
							$title = $videos[ $found['key'] ]['title'];
						} elseif ( isset( $rows[ $found['key'] ] ) ) {
							$thumb = (string) $rows[ $found['key'] ]['thumbnail'];
							$title = (string) $rows[ $found['key'] ]['title'];
						} elseif ( 'youtube' === $found['provider'] ) {
							$thumb = 'https://i.ytimg.com/vi/' . $found['id'] . '/hqdefault.jpg';
							$title = '';
						} else {
							$thumb = '';
							$title = '';
						}

						if ( '' !== $thumb ) {
							$script = true;

							return self::facade( $tag, $found, $thumb, $title, $index );
						}
					}

					if ( $index >= 0 ) {
						return self::mark( $tag, $index );
					}

					return $tag;
				},
				$content
			);
		}

		if ( $main && $videos ) {
			foreach ( $videos as $video ) {
				$content .= self::transcript( $video );

				if ( $video['clips'] ) {
					$script = true;
				}
			}
		}

		if ( $script ) {
			wp_enqueue_script( 'video-seo-front', VIDEO_SEO_URL . 'assets/front.js', array(), VIDEO_SEO_VERSION, true );
		}

		// The next piece of content (another post on an archive) starts afresh.
		self::$placed   = array();
		self::$css_done = false;

		return $content;
	}

	/**
	 * Video rows for the embeds in some content (facades outside the main
	 * post, such as on archive pages).
	 *
	 * @param string $content Content.
	 * @return array<string,array>
	 */
	private static function rows_in( $content ) {
		$keys = array();

		if ( preg_match_all( '#<iframe\b[^>]*?\ssrc="([^"]+)"#i', $content, $m ) ) {
			foreach ( $m[1] as $src ) {
				$found = Video_SEO_Detector::from_url( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );

				if ( $found ) {
					$keys[] = $found['key'];
				}
			}
		}

		return $keys ? Video_SEO_DB::get_by_keys( $keys ) : array();
	}

	/**
	 * Mark a player with its position on the page (for ?t= links).
	 *
	 * @param string $tag   Opening tag (or whole iframe).
	 * @param int    $index Position.
	 * @return string
	 */
	private static function mark( $tag, $index ) {
		if ( false !== strpos( $tag, 'data-vseo-index' ) ) {
			return $tag;
		}

		return preg_replace( '#^<(iframe|video)\b#i', '<$1 data-vseo-index="' . (int) $index . '"', $tag, 1 );
	}

	/**
	 * A click-to-play preview: the thumbnail and a play button, with the real
	 * player kept in a <template> until it's clicked.
	 *
	 * @param string $iframe The player's iframe HTML, unchanged.
	 * @param array  $found  Detected video.
	 * @param string $thumb  Thumbnail URL.
	 * @param string $title  Video title.
	 * @param int    $index  Position on the page (-1 when unknown).
	 * @return string
	 */
	private static function facade( $iframe, array $found, $thumb, $title, $index ) {
		$width  = preg_match( '#\swidth="(\d+)"#i', $iframe, $w ) ? (int) $w[1] : 0;
		$height = preg_match( '#\sheight="(\d+)"#i', $iframe, $h ) ? (int) $h[1] : 0;
		$ratio  = $width > 0 && $height > 0 ? $width . ' / ' . $height : '16 / 9';
		$style  = 'aspect-ratio:' . $ratio . ';';

		if ( $width > 0 ) {
			$style .= 'max-width:' . $width . 'px;';
		}

		if ( '' === $title && preg_match( '#\stitle="([^"]*)"#i', $iframe, $t ) ) {
			$title = html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' );
		}

		$label = '' !== $title
			/* translators: %s: video title. */
			? sprintf( __( 'Play video: %s', 'video-seo' ), $title )
			: __( 'Play video', 'video-seo' );

		$html  = self::styles();
		$html .= '<div class="vseo-facade vseo-facade--' . esc_attr( $found['provider'] ) . '" style="' . esc_attr( $style ) . '"' . ( $index >= 0 ? ' data-vseo-index="' . (int) $index . '"' : '' ) . '>';
		$html .= '<button type="button" class="vseo-facade-play" aria-label="' . esc_attr( $label ) . '">';
		$html .= '<img class="vseo-facade-img" src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async">';
		$html .= '<span class="vseo-facade-icon" aria-hidden="true"><svg viewBox="0 0 68 48" width="68" height="48" focusable="false"><path class="vseo-facade-bg" d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.2.3 34 .3 34 .3s-21.2 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.8 47.7 34 47.7 34 47.7s21.2 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z"/><path d="M45 24 27 14v20z" fill="#fff"/></svg></span>';
		if ( '' !== $title ) {
			$html .= '<span class="vseo-facade-title">' . esc_html( $title ) . '</span>';
		}
		$html .= '</button>';
		$html .= '<template class="vseo-facade-player">' . $iframe . '</template>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * A video's transcript as a collapsible box, once per page.
	 *
	 * @param array $video Built video.
	 * @return string
	 */
	private static function transcript( array $video ) {
		if ( empty( $video['show_transcript'] ) || '' === $video['transcript'] || isset( self::$placed[ $video['key'] ] ) ) {
			return '';
		}

		self::$placed[ $video['key'] ] = true;

		$summary = count( self::page_videos() ) > 1
			/* translators: %s: video title. */
			? sprintf( __( 'Transcript: %s', 'video-seo' ), $video['title'] )
			: __( 'Video transcript', 'video-seo' );

		return self::styles()
			. '<details class="vseo-transcript"><summary>' . esc_html( $summary ) . '</summary><div class="vseo-transcript-text">'
			. wpautop( esc_html( $video['transcript'] ) )
			. '</div></details>';
	}

	/**
	 * The small stylesheet for previews and transcripts, inlined before the
	 * first one in each piece of content so nothing jumps as the page loads.
	 *
	 * @return string
	 */
	private static function styles() {
		if ( self::$css_done ) {
			return '';
		}

		self::$css_done = true;

		$css = '.vseo-facade{position:relative;display:block;width:100%;margin:0 auto;background:#000;overflow:hidden}'
			. '@supports not (aspect-ratio:1/1){.vseo-facade{height:0;padding-top:56.25%}}'
			. '.wp-has-aspect-ratio .vseo-facade{position:absolute;inset:0;max-width:none!important;height:100%}'
			. '.vseo-facade-play{position:absolute;inset:0;display:block;width:100%;height:100%;margin:0;padding:0;border:0;border-radius:0;background:none;box-shadow:none;cursor:pointer;font:inherit}'
			. '.vseo-facade-img{position:absolute;inset:0;display:block;width:100%!important;height:100%!important;max-width:none!important;margin:0!important;border:0;border-radius:0;object-fit:cover}'
			. '.vseo-facade-icon{position:absolute;top:50%;left:50%;width:68px;height:48px;transform:translate(-50%,-50%);transition:transform .15s}'
			. '.vseo-facade-icon svg{display:block;width:68px;height:48px}'
			. '.vseo-facade-bg{fill:#212121;fill-opacity:.82;transition:fill .15s,fill-opacity .15s}'
			. '.vseo-facade--youtube .vseo-facade-play:hover .vseo-facade-bg,.vseo-facade--youtube .vseo-facade-play:focus-visible .vseo-facade-bg{fill:#f00;fill-opacity:1}'
			. '.vseo-facade--vimeo .vseo-facade-play:hover .vseo-facade-bg,.vseo-facade--vimeo .vseo-facade-play:focus-visible .vseo-facade-bg{fill:#00adef;fill-opacity:1}'
			. '.vseo-facade-play:focus-visible{outline:3px solid #fff;outline-offset:-6px}'
			. '.vseo-facade-title{position:absolute;top:0;right:0;left:0;padding:14px 16px 28px;background:linear-gradient(rgba(0,0,0,.6),rgba(0,0,0,0));color:#fff;font-size:16px;line-height:1.35;text-align:left;text-shadow:0 1px 2px rgba(0,0,0,.4);overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'
			. '.vseo-transcript{margin:1em 0 1.5em;padding:0;border:1px solid rgba(127,127,127,.35);border-radius:6px}'
			. '.vseo-transcript>summary{padding:.7em 1em;font-weight:600;cursor:pointer}'
			. '.vseo-transcript-text{max-height:420px;overflow:auto;padding:0 1em .2em;border-top:1px solid rgba(127,127,127,.25)}'
			. '.vseo-transcript-text p{margin:.8em 0}';

		return '<style id="video-seo-css">' . $css . '</style>';
	}
}
