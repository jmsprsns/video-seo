<?php
/**
 * Finds the videos embedded in a post: YouTube, Vimeo and video files, in
 * blocks, classic content, shortcodes and page builders' saved data.
 *
 * Only embeds count. A plain link to a video, or a video URL in the middle of
 * a sentence, isn't a video on the page, so it gets no schema.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video detection.
 */
final class Video_SEO_Detector {

	/**
	 * Bump when detection changes, so posts are scanned again.
	 */
	const VERSION = '1';

	/**
	 * Most videos read from one post.
	 */
	const MAX_VIDEOS = 30;

	/**
	 * Video file extensions.
	 */
	const FILE_TYPES = 'mp4|m4v|webm|ogv|mov';

	/**
	 * Page builders' meta keys holding a page's layout.
	 *
	 * @return string[]
	 */
	public static function builder_meta_keys() {
		$keys = array(
			'_elementor_data',          // Elementor.
			'_bricks_page_content_2',   // Bricks.
			'_fl_builder_data',         // Beaver Builder.
			'ct_builder_shortcodes',    // Oxygen (classic).
			'ct_builder_json',          // Oxygen 4+.
			'tve_updated_post',         // Thrive Architect.
			'_themify_builder_settings_json', // Themify.
			'_cornerstone_data',        // Cornerstone.
			'_breakdance_data',         // Breakdance.
		);

		/**
		 * Filters the post meta keys read for videos, besides post_content.
		 *
		 * Add a custom field here to have videos stored in it detected.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return (array) apply_filters( 'video_seo_meta_keys', $keys );
	}

	/**
	 * Videos in a saved post: its content first, then its builder data.
	 *
	 * @param WP_Post     $post    Post.
	 * @param string|null $content Content to read instead of the saved content (the editor's unsaved copy).
	 * @return array{videos:array[],words:int,before:int}
	 */
	public static function scan_post( WP_Post $post, $content = null ) {
		$content = null === $content ? (string) $post->post_content : (string) $content;
		$main    = self::normalize( $content );
		$found   = self::find( $main );
		$keys    = array();

		foreach ( $found as $video ) {
			$keys[ $video['key'] ] = true;
		}

		foreach ( self::builder_meta_keys() as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );

			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			foreach ( self::find( self::normalize( $value ) ) as $video ) {
				if ( count( $keys ) >= self::MAX_VIDEOS ) {
					break 2;
				}

				if ( ! isset( $keys[ $video['key'] ] ) ) {
					$video['offset']       = -1;
					$found[]               = $video;
					$keys[ $video['key'] ] = true;
				}
			}
		}

		$words  = self::count_words( $main );
		$before = -1;

		if ( $found && $found[0]['offset'] >= 0 ) {
			$before = self::count_words( substr( $main, 0, $found[0]['offset'] ) );
		}

		/**
		 * Filters the videos found in a post.
		 *
		 * @param array[] $found Videos ({key, provider, id, url, hash, poster, attachment_id, offset}).
		 * @param WP_Post $post  Post.
		 */
		$found = (array) apply_filters( 'video_seo_detected_videos', $found, $post );

		return array(
			'videos' => array_slice( array_values( $found ), 0, self::MAX_VIDEOS ),
			'words'  => $words,
			'before' => $before,
		);
	}

	/**
	 * Undo JSON and HTML escaping, so URLs read the same in block attributes,
	 * builder JSON and HTML.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = str_replace( '\\/', '/', (string) $text );
		$text = preg_replace_callback(
			'/\\\\u00(26|3[cCdDeEfF]|22|27)/',
			function ( $m ) {
				return chr( hexdec( $m[1] ) );
			},
			$text
		);

		// Only the entities that hide parts of a URL. Escaped quotes and angle
		// brackets stay, so a code sample showing an <iframe> isn't an embed.
		return str_replace(
			array( '&amp;', '&#038;', '&#38;', '&#x2F;', '&#x2f;', '&#047;', '&#47;' ),
			array( '&', '&', '&', '/', '/', '/', '/' ),
			$text
		);
	}

	/**
	 * Videos in normalized text, in the order they appear, without repeats.
	 *
	 * @param string $text Normalized text ({@see normalize()}).
	 * @return array[]
	 */
	public static function find( $text ) {
		$text = (string) $text;

		if ( '' === $text || ! preg_match( '/youtu|vimeo|\.(?:' . self::FILE_TYPES . ')\b/i', $text ) ) {
			return array();
		}

		// Links and code samples aren't embeds: blank them out, keeping
		// offsets for word counts.
		$text = preg_replace_callback(
			'#<a\b[^>]*>.*?</a>|<pre\b.*?</pre>|<code\b.*?</code>#is',
			function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) );
			},
			$text
		);

		$posters = self::posters( $text );
		$hits    = array();

		// A URL counts when it's an attribute or JSON value, a shortcode's
		// content, or alone on its line (WordPress embeds those). Prose doesn't.
		$start = '(?:^[ \t]*|(?<=["\'>\]=]))';
		$end   = '(?=[ \t]*(?:\\\\?["\'<\[\]\r\n]|$))';
		$tail  = '[^\s"\'<>\[\]\\\\]*';

		$patterns = array(
			'youtube' => '~' . $start . '((?:https?:)?(?://)?(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch/?\?(?:[^\s"\'<>#]*?&)?v=|embed/|shorts/|live/|v/|e/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])' . $tail . ')' . $end . '~im',
			'vimeo'   => '~' . $start . '((?:https?:)?(?://)?(?:www\.)?(?:player\.)?vimeo\.com/(?:video/|channels/[\w-]+/|groups/[\w-]+/videos/|album/\d+/video/|showcase/\d+/video/)?(\d{6,12})(?![\d])(?:/([0-9a-f]{6,20})(?![0-9a-z]))?' . $tail . ')' . $end . '~im',
			'file'    => '~' . $start . '((?:https?:)?(?://[^\s"\'<>\[\]()\\\\]+|/[^\s"\'<>\[\]()\\\\]*)\.(?:' . self::FILE_TYPES . ')(?:[?#][^\s"\'<>\[\]\\\\]*)?)' . $end . '~im',
		);

		foreach ( $patterns as $provider => $pattern ) {
			if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches as $m ) {
				$video = self::from_match( $provider, $m, $posters );

				if ( $video ) {
					$video['offset'] = (int) $m[0][1];
					$hits[]          = $video;
				}
			}
		}

		usort(
			$hits,
			function ( $a, $b ) {
				return $a['offset'] - $b['offset'];
			}
		);

		$out = array();

		foreach ( $hits as $video ) {
			if ( isset( $out[ $video['key'] ] ) ) {
				// Keep what a later copy of the same video adds.
				foreach ( array( 'hash', 'poster', 'attachment_id' ) as $field ) {
					if ( empty( $out[ $video['key'] ][ $field ] ) && ! empty( $video[ $field ] ) ) {
						$out[ $video['key'] ][ $field ] = $video[ $field ];
					}
				}
				continue;
			}

			$out[ $video['key'] ] = $video;

			if ( count( $out ) >= self::MAX_VIDEOS ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * Build a video from a regex match.
	 *
	 * @param string $provider youtube|vimeo|file.
	 * @param array  $m        Match with offsets.
	 * @param array  $posters  Poster images by file key.
	 * @return array|null
	 */
	private static function from_match( $provider, array $m, array $posters ) {
		$raw = $m[1][0];

		if ( 'youtube' === $provider ) {
			$id = $m[2][0];

			// Playlist embeds aren't one video.
			if ( 'videoseries' === $id ) {
				return null;
			}

			return self::video( 'youtube', $id, 'https://www.youtube.com/watch?v=' . $id );
		}

		if ( 'vimeo' === $provider ) {
			$id   = $m[2][0];
			$hash = isset( $m[3] ) && '' !== $m[3][0] && -1 !== $m[3][1] ? $m[3][0] : '';

			if ( '' === $hash && preg_match( '/[?&]h=([0-9a-f]{6,20})\b/', $raw, $h ) ) {
				$hash = $h[1];
			}

			$video         = self::video( 'vimeo', $id, 'https://vimeo.com/' . $id . ( '' !== $hash ? '/' . $hash : '' ) );
			$video['hash'] = $hash;

			return $video;
		}

		$url = self::absolute_url( $raw );

		if ( '' === $url ) {
			return null;
		}

		$key    = self::file_key( $url );
		$video  = self::video( 'file', '', $url, $key );
		$poster = isset( $posters[ $key ] ) ? $posters[ $key ] : array();

		$video['poster']        = isset( $poster['poster'] ) ? $poster['poster'] : '';
		$video['attachment_id'] = isset( $poster['id'] ) ? (int) $poster['id'] : 0;

		return $video;
	}

	/**
	 * A detected video.
	 *
	 * @param string $provider Provider.
	 * @param string $id       Provider's ID.
	 * @param string $url      Canonical URL.
	 * @param string $key      Key (built from provider and ID when empty).
	 * @return array
	 */
	private static function video( $provider, $id, $url, $key = '' ) {
		return array(
			'key'           => '' !== $key ? $key : $provider . ':' . $id,
			'provider'      => $provider,
			'id'            => $id,
			'url'           => $url,
			'hash'          => '',
			'poster'        => '',
			'attachment_id' => 0,
			'offset'        => 0,
		);
	}

	/**
	 * Poster images (and media library IDs) for video files, read from
	 * <video> tags, [video] shortcodes and Video blocks.
	 *
	 * @param string $text Normalized text.
	 * @return array<string,array{poster:string,id:int}>
	 */
	private static function posters( $text ) {
		$out = array();

		if ( ! preg_match_all( '#<!--\s*wp:video\s+(\{.*?\})\s*-->(.*?)<!--\s*/wp:video\s*-->|<video\b([^>]*)>(.*?)</video>|\[video\b([^\]]*)\]#is', $text, $sets, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $sets as $set ) {
			$id    = 0;
			$attrs = array();
			$inner = '';

			if ( ! empty( $set[1] ) ) {
				// A Video block: the media library ID is in its JSON.
				$json  = json_decode( $set[1], true );
				$id    = is_array( $json ) && isset( $json['id'] ) ? absint( $json['id'] ) : 0;
				$inner = $set[2];

				if ( preg_match( '#<video\b([^>]*)>#i', $inner, $tag ) ) {
					$attrs = self::attributes( $tag[1] );
				}
			} elseif ( ! empty( $set[3] ) || ! empty( $set[4] ) ) {
				$attrs = self::attributes( $set[3] );
				$inner = $set[4];
			} elseif ( isset( $set[5] ) ) {
				$attrs = self::attributes( $set[5] );
			}

			$poster = isset( $attrs['poster'] ) ? self::absolute_url( $attrs['poster'] ) : '';
			$files  = array();

			foreach ( array( 'src', 'mp4', 'm4v', 'webm', 'ogv', 'mov' ) as $name ) {
				if ( ! empty( $attrs[ $name ] ) ) {
					$files[] = $attrs[ $name ];
				}
			}

			if ( '' !== $inner && preg_match_all( '#<source\b([^>]*)>#i', $inner, $sources ) ) {
				foreach ( $sources[1] as $source ) {
					$source_attrs = self::attributes( $source );

					if ( ! empty( $source_attrs['src'] ) ) {
						$files[] = $source_attrs['src'];
					}
				}
			}

			foreach ( $files as $file ) {
				$url = self::absolute_url( $file );

				if ( '' === $url ) {
					continue;
				}

				$key = self::file_key( $url );

				if ( ! isset( $out[ $key ] ) || ( '' === $out[ $key ]['poster'] && '' !== $poster ) ) {
					$out[ $key ] = array(
						'poster' => $poster,
						'id'     => $id,
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Parse HTML or shortcode attributes.
	 *
	 * @param string $text Attribute text.
	 * @return array<string,string> Lower-case names.
	 */
	private static function attributes( $text ) {
		$out = array();

		if ( preg_match_all( '/([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\]]+))/', (string) $text, $sets, PREG_SET_ORDER ) ) {
			foreach ( $sets as $set ) {
				$value                       = isset( $set[4] ) && '' !== $set[4] ? $set[4] : ( isset( $set[3] ) && '' !== $set[3] ? $set[3] : $set[2] );
				$out[ strtolower( $set[1] ) ] = trim( $value );
			}
		}

		return $out;
	}

	/**
	 * Make a video file URL absolute (http/https only).
	 *
	 * @param string $url URL as written.
	 * @return string Empty when it isn't a usable web address.
	 */
	public static function absolute_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		if ( ! preg_match( '#^https?://[^/\s]+#i', $url ) ) {
			return '';
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Key for a video file: the same file at http/https, or with a cache-busting
	 * query string, is one video.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public static function file_key( $url ) {
		$plain = preg_replace( '/[?#].*$/', '', (string) $url );
		$plain = preg_replace( '#^https?://(?:www\.)?#i', '', $plain );

		return 'file:' . md5( strtolower( $plain ) );
	}

	/**
	 * Whether a string is a valid video key.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^(?:youtube:[A-Za-z0-9_-]{11}|vimeo:\d{6,12}|file:[a-f0-9]{32})$/', $key );
	}

	/**
	 * The video an embed URL points to (for embeds being rendered).
	 *
	 * @param string $url URL or iframe src.
	 * @return array|null
	 */
	public static function from_url( $url ) {
		$found = self::find( '"' . self::normalize( (string) $url ) . '"' );

		return $found ? $found[0] : null;
	}

	/**
	 * Count the visible words in content (tags, block comments, shortcodes and
	 * bare URLs left out).
	 *
	 * @param string $text Normalized content.
	 * @return int
	 */
	public static function count_words( $text ) {
		$text = preg_replace( '/<!--.*?-->/s', ' ', (string) $text );
		$text = preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', $text );
		$text = preg_replace( '/\[\/?[a-z][^\]]*\]/i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '#\b(?:https?://|www\.)\S+#i', ' ', $text );

		$count = preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’_-]*/u', $text );

		return false === $count ? str_word_count( $text ) : (int) $count;
	}
}
