<?php
/**
 * Fetches each video's details: from the YouTube Data API (with a key) or
 * YouTube's oEmbed (without one), from Vimeo's oEmbed, and from the file
 * itself for videos in the media library.
 *
 * Only fixed provider addresses are called, with IDs that passed the
 * detector's patterns. Video files on other sites are never downloaded.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video details from providers.
 */
final class Video_SEO_Fetcher {

	/**
	 * YouTube Data API v3 videos endpoint.
	 */
	const YOUTUBE_API = 'https://www.googleapis.com/youtube/v3/videos';

	/**
	 * Option recording the YouTube API key's last result.
	 */
	const API_STATUS_OPTION = 'video_seo_api_status';

	/**
	 * Seconds to wait for a provider.
	 */
	const TIMEOUT = 10;

	/**
	 * Most attempts before a failing video is retried only daily.
	 */
	const MAX_ATTEMPTS = 6;

	/**
	 * Fetch details for videos that are due, within a time budget.
	 *
	 * @param int $budget Seconds to spend.
	 * @return int Videos processed.
	 */
	public static function process( $budget = 20 ) {
		$start = microtime( true );
		$done  = 0;
		$plan  = array(
			'youtube' => self::youtube_key() ? 50 : 15,
			'vimeo'   => 10,
			'file'    => 25,
		);

		foreach ( $plan as $provider => $limit ) {
			if ( microtime( true ) - $start > $budget ) {
				break;
			}

			$rows = Video_SEO_DB::due( $provider, $limit );

			if ( $rows ) {
				$done += self::fetch_rows( $rows, $start + $budget );
			}
		}

		return $done;
	}

	/**
	 * Fetch details for these videos now.
	 *
	 * @param array[] $rows     Video rows.
	 * @param float   $deadline Stop starting new requests after this time (0 = none).
	 * @return int Videos processed.
	 */
	public static function fetch_rows( array $rows, $deadline = 0.0 ) {
		$by_provider = array();

		foreach ( $rows as $row ) {
			$by_provider[ $row['provider'] ][] = $row;
		}

		$done = 0;

		if ( ! empty( $by_provider['youtube'] ) ) {
			try {
				$done += self::fetch_youtube( $by_provider['youtube'], $deadline );
			} catch ( Throwable $e ) {
				self::record_crash( $by_provider['youtube'], $e );
			}
		}

		foreach ( array( 'vimeo', 'file' ) as $provider ) {
			if ( empty( $by_provider[ $provider ] ) ) {
				continue;
			}

			foreach ( $by_provider[ $provider ] as $row ) {
				if ( $deadline && microtime( true ) > $deadline ) {
					break 2;
				}

				// One odd video must never stop the rest of the queue.
				try {
					if ( 'vimeo' === $provider ) {
						self::fetch_vimeo( $row );
					} else {
						self::fetch_file( $row );
					}
				} catch ( Throwable $e ) {
					self::record_crash( array( $row ), $e );
				}

				++$done;
			}
		}

		return $done;
	}

	// YouTube ---------------------------------------------------------------------------

	/**
	 * The saved YouTube API key.
	 *
	 * @return string
	 */
	public static function youtube_key() {
		return (string) Video_SEO_Plugin::instance()->get_setting( 'youtube_key' );
	}

	/**
	 * YouTube videos: the API in batches of 50 when there's a key, otherwise
	 * (or when the key fails) oEmbed one by one.
	 *
	 * @param array[] $rows     YouTube rows.
	 * @param float   $deadline Stop time.
	 * @return int Videos processed.
	 */
	private static function fetch_youtube( array $rows, $deadline ) {
		$key  = self::youtube_key();
		$done = 0;

		if ( '' !== $key ) {
			$failed = array();

			foreach ( array_chunk( $rows, 50 ) as $chunk ) {
				$items = self::youtube_api( wp_list_pluck( $chunk, 'provider_id' ), $key );

				if ( is_wp_error( $items ) ) {
					$failed = array_merge( $failed, $chunk );
					continue;
				}

				foreach ( $chunk as $row ) {
					if ( isset( $items[ $row['provider_id'] ] ) ) {
						self::save_youtube_item( $row, $items[ $row['provider_id'] ] );
					} else {
						self::save(
							$row,
							array(),
							'unavailable',
							'api',
							__( 'YouTube says this video is private or was deleted.', 'video-seo' )
						);
					}

					++$done;
				}
			}

			// The key didn't work (quota, restrictions): get the basics from oEmbed.
			$rows = $failed;
		}

		foreach ( $rows as $row ) {
			if ( $deadline && microtime( true ) > $deadline ) {
				break;
			}

			self::youtube_oembed( $row );
			++$done;
		}

		return $done;
	}

	/**
	 * Call the YouTube Data API for up to 50 videos.
	 *
	 * @param string[] $ids Video IDs.
	 * @param string   $key API key.
	 * @return array<string,array>|WP_Error Items by video ID.
	 */
	private static function youtube_api( array $ids, $key ) {
		$ids = array_values( array_filter( $ids, array( __CLASS__, 'is_youtube_id' ) ) );

		if ( ! $ids ) {
			return array();
		}

		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'part'       => 'snippet,contentDetails,status,statistics',
					'id'         => implode( ',', $ids ),
					'maxResults' => 50,
					'key'        => rawurlencode( $key ),
				),
				self::YOUTUBE_API
			),
			self::request_args()
		);

		$error = self::youtube_api_error( $response );

		if ( $error ) {
			self::set_api_status( false, $error->get_error_message(), $error->get_error_code() );
			return $error;
		}

		self::set_api_status( true );

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = array();

		if ( is_array( $body ) && ! empty( $body['items'] ) && is_array( $body['items'] ) ) {
			foreach ( $body['items'] as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) && is_string( $item['id'] ) ) {
					$items[ $item['id'] ] = $item;
				}
			}
		}

		return $items;
	}

	/**
	 * Turn a failed API response into a plain-English error.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return WP_Error|null Null when the request worked.
	 */
	private static function youtube_api_error( $response ) {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error from WordPress's HTTP API. */
			return new WP_Error( 'http', sprintf( __( 'Couldn\'t reach YouTube: %s', 'video-seo' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$reasons = array();
		$message = '';

		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$message = isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ? $body['error']['message'] : '';

			foreach ( array( 'errors', 'details' ) as $list ) {
				if ( ! empty( $body['error'][ $list ] ) && is_array( $body['error'][ $list ] ) ) {
					foreach ( $body['error'][ $list ] as $entry ) {
						if ( is_array( $entry ) && ! empty( $entry['reason'] ) && is_string( $entry['reason'] ) ) {
							$reasons[] = $entry['reason'];
						}
					}
				}
			}
		}

		$has = function ( array $names ) use ( $reasons ) {
			return (bool) array_intersect( $names, $reasons );
		};

		if ( $has( array( 'keyInvalid', 'API_KEY_INVALID' ) ) || false !== stripos( $message, 'API key not valid' ) ) {
			return new WP_Error( 'invalid', __( 'This API key isn\'t valid. Check that it was copied in full.', 'video-seo' ) );
		}

		if ( $has( array( 'quotaExceeded', 'dailyLimitExceeded', 'RATE_LIMIT_EXCEEDED', 'rateLimitExceeded' ) ) ) {
			return new WP_Error( 'quota', __( 'This key\'s daily YouTube quota is used up. Details are fetched again once it resets (midnight Pacific time).', 'video-seo' ) );
		}

		if ( $has( array( 'accessNotConfigured', 'SERVICE_DISABLED' ) ) ) {
			return new WP_Error( 'disabled', __( 'The YouTube Data API v3 isn\'t turned on for this key\'s Google Cloud project. Turn it on in the API Library, then test again.', 'video-seo' ) );
		}

		if ( $has( array( 'API_KEY_HTTP_REFERRER_BLOCKED', 'ipRefererBlocked', 'API_KEY_IP_ADDRESS_BLOCKED', 'API_KEY_SERVICE_BLOCKED', 'forbidden' ) ) ) {
			return new WP_Error( 'restricted', __( 'This key\'s restrictions block requests from your server. In Google Cloud, set Application restrictions to None (or your server\'s IP address, not websites), and allow the YouTube Data API v3.', 'video-seo' ) );
		}

		$message = '' !== $message ? sanitize_text_field( $message ) : __( 'Unknown error.', 'video-seo' );

		/* translators: 1: HTTP status code, 2: error message from YouTube. */
		return new WP_Error( 'http_' . $code, sprintf( __( 'YouTube answered with an error (%1$d): %2$s', 'video-seo' ), $code, $message ) );
	}

	/**
	 * Check a YouTube API key with one cheap request.
	 *
	 * @param string $key API key.
	 * @return true|WP_Error
	 */
	public static function test_key( $key ) {
		if ( ! self::is_youtube_key( $key ) ) {
			return new WP_Error( 'format', __( 'That doesn\'t look like a Google API key. Keys are about 39 letters, numbers, dashes and underscores, starting with "AIza".', 'video-seo' ) );
		}

		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'part' => 'id',
					'id'   => 'M7lc1UVf-VE', // YouTube's own API demo video.
					'key'  => rawurlencode( $key ),
				),
				self::YOUTUBE_API
			),
			self::request_args()
		);

		$error = self::youtube_api_error( $response );

		if ( $error ) {
			return $error;
		}

		return true;
	}

	/**
	 * Remember the API key's last result (written only when it changes).
	 *
	 * @param bool   $ok      Whether the call worked.
	 * @param string $message Error message.
	 * @param string $code    Error code.
	 * @return void
	 */
	public static function set_api_status( $ok, $message = '', $code = '' ) {
		$current = get_option( self::API_STATUS_OPTION, array() );
		$new     = array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
			'code'    => (string) $code,
		);

		if ( is_array( $current ) && isset( $current['ok'] ) && $current['ok'] === $new['ok'] && $current['code'] === $new['code'] ) {
			return;
		}

		$new['time'] = time();
		update_option( self::API_STATUS_OPTION, $new, false );
	}

	/**
	 * Save a video from an API item.
	 *
	 * @param array $row  Video row.
	 * @param array $item API item.
	 * @return void
	 */
	private static function save_youtube_item( array $row, array $item ) {
		$snippet = isset( $item['snippet'] ) && is_array( $item['snippet'] ) ? $item['snippet'] : array();
		$details = isset( $item['contentDetails'] ) && is_array( $item['contentDetails'] ) ? $item['contentDetails'] : array();
		$status  = isset( $item['status'] ) && is_array( $item['status'] ) ? $item['status'] : array();
		$stats   = isset( $item['statistics'] ) && is_array( $item['statistics'] ) ? $item['statistics'] : array();

		$thumb = '';
		$size  = array( 0, 0 );

		if ( ! empty( $snippet['thumbnails'] ) && is_array( $snippet['thumbnails'] ) ) {
			foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $name ) {
				if ( ! empty( $snippet['thumbnails'][ $name ]['url'] ) && is_string( $snippet['thumbnails'][ $name ]['url'] ) ) {
					$thumb = $snippet['thumbnails'][ $name ]['url'];
					$size  = array(
						isset( $snippet['thumbnails'][ $name ]['width'] ) ? (int) $snippet['thumbnails'][ $name ]['width'] : 0,
						isset( $snippet['thumbnails'][ $name ]['height'] ) ? (int) $snippet['thumbnails'][ $name ]['height'] : 0,
					);
					break;
				}
			}
		}

		$published = isset( $snippet['publishedAt'] ) && is_string( $snippet['publishedAt'] ) ? strtotime( $snippet['publishedAt'] ) : false;

		$data = array(
			'title'       => isset( $snippet['title'] ) ? self::clean_line( $snippet['title'] ) : '',
			'description' => isset( $snippet['description'] ) ? self::clean_text( $snippet['description'] ) : '',
			'thumbnail'   => '' !== $thumb ? esc_url_raw( $thumb ) : 'https://i.ytimg.com/vi/' . $row['provider_id'] . '/hqdefault.jpg',
			'duration'    => isset( $details['duration'] ) && is_string( $details['duration'] ) ? self::iso_seconds( $details['duration'] ) : 0,
			'upload_date' => $published ? gmdate( 'Y-m-d H:i:s', $published ) : null,
			'views'       => isset( $stats['viewCount'] ) && is_numeric( $stats['viewCount'] ) ? (int) $stats['viewCount'] : null,
			'extra'       => array(
				'channel'  => isset( $snippet['channelTitle'] ) ? self::clean_line( $snippet['channelTitle'] ) : '',
				'live'     => isset( $snippet['liveBroadcastContent'] ) ? sanitize_key( $snippet['liveBroadcastContent'] ) : '',
				'privacy'  => isset( $status['privacyStatus'] ) ? sanitize_key( $status['privacyStatus'] ) : '',
				'thumb_w'  => $size[0],
				'thumb_h'  => $size[1],
				'captions' => isset( $details['caption'] ) && 'true' === $details['caption'],
			),
		);

		if ( isset( $status['embeddable'] ) && false === $status['embeddable'] ) {
			self::save( $row, $data, 'unavailable', 'api', __( 'Embedding is turned off for this video on YouTube, so it can\'t play on your page.', 'video-seo' ) );
			return;
		}

		self::save( $row, $data, 'ok', 'api' );
	}

	/**
	 * Basic details from YouTube's oEmbed: title and thumbnail.
	 *
	 * @param array $row Video row.
	 * @return void
	 */
	private static function youtube_oembed( array $row ) {
		if ( ! self::is_youtube_id( $row['provider_id'] ) ) {
			self::save( $row, array(), 'unavailable', 'none', __( 'Not a valid YouTube video ID.', 'video-seo' ) );
			return;
		}

		$response = wp_safe_remote_get(
			'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $row['provider_id'] ),
			self::request_args()
		);

		if ( is_wp_error( $response ) ) {
			self::fail( $row, $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			self::save( $row, array(), 'unavailable', 'oembed', __( 'Embedding is turned off for this video on YouTube, so it can\'t play on your page.', 'video-seo' ) );
			return;
		}

		if ( in_array( $code, array( 400, 403, 404 ), true ) ) {
			self::save( $row, array(), 'unavailable', 'oembed', __( 'YouTube says this video is private or was deleted.', 'video-seo' ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			/* translators: %d: HTTP status code. */
			self::fail( $row, sprintf( __( 'YouTube answered with HTTP %d.', 'video-seo' ), $code ) );
			return;
		}

		self::save(
			$row,
			array(
				'title'     => isset( $body['title'] ) && is_string( $body['title'] ) ? self::clean_line( $body['title'] ) : '',
				'thumbnail' => 'https://i.ytimg.com/vi/' . $row['provider_id'] . '/hqdefault.jpg',
				'extra'     => array(
					'channel' => isset( $body['author_name'] ) && is_string( $body['author_name'] ) ? self::clean_line( $body['author_name'] ) : '',
					'thumb_w' => 480,
					'thumb_h' => 360,
				),
			),
			'ok',
			'oembed'
		);
	}

	// Vimeo -----------------------------------------------------------------------------

	/**
	 * Details from Vimeo's oEmbed. The site's address goes along as the
	 * referrer, so videos limited to embedding on this domain still answer.
	 *
	 * @param array $row Video row.
	 * @return void
	 */
	private static function fetch_vimeo( array $row ) {
		if ( ! preg_match( '/^\d{6,12}$/', $row['provider_id'] ) ) {
			self::save( $row, array(), 'unavailable', 'none', __( 'Not a valid Vimeo video ID.', 'video-seo' ) );
			return;
		}

		$hash = isset( $row['extra']['hash'] ) && preg_match( '/^[0-9a-f]{6,20}$/', $row['extra']['hash'] ) ? $row['extra']['hash'] : '';
		$url  = 'https://vimeo.com/' . $row['provider_id'] . ( '' !== $hash ? '/' . $hash : '' );
		$args = self::request_args();

		$args['headers']['Referer'] = home_url( '/' );

		$response = wp_safe_remote_get( 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( $url ), $args );

		if ( is_wp_error( $response ) ) {
			self::fail( $row, $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 403 === $code ) {
			self::save( $row, array(), 'unavailable', 'oembed', __( 'Vimeo\'s privacy settings hide this video from other sites. If it\'s limited to your own domain, add this site\'s domain in the video\'s embed settings on Vimeo.', 'video-seo' ) );
			return;
		}

		if ( 404 === $code ) {
			self::save( $row, array(), 'unavailable', 'oembed', __( 'Vimeo couldn\'t find this video. It may be private or deleted.', 'video-seo' ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			/* translators: %d: HTTP status code. */
			self::fail( $row, sprintf( __( 'Vimeo answered with HTTP %d.', 'video-seo' ), $code ) );
			return;
		}

		$thumb = isset( $body['thumbnail_url'] ) && is_string( $body['thumbnail_url'] ) ? $body['thumbnail_url'] : '';

		// Ask Vimeo's image server for a large size instead of the 640px default.
		$thumb = preg_replace( '/-d_\d+(?:x\d+)?(?=[?.]|$)/', '-d_1280', $thumb );
		$date  = isset( $body['upload_date'] ) && is_string( $body['upload_date'] ) ? strtotime( $body['upload_date'] . ' UTC' ) : false;

		self::save(
			$row,
			array(
				'title'       => isset( $body['title'] ) && is_string( $body['title'] ) ? self::clean_line( $body['title'] ) : '',
				'description' => isset( $body['description'] ) && is_string( $body['description'] ) ? self::clean_text( $body['description'] ) : '',
				'thumbnail'   => '' !== $thumb && 0 === strpos( $thumb, 'https://' ) ? esc_url_raw( $thumb ) : '',
				'duration'    => isset( $body['duration'] ) ? absint( $body['duration'] ) : 0,
				'upload_date' => $date ? gmdate( 'Y-m-d H:i:s', $date ) : null,
				'width'       => isset( $body['width'] ) ? absint( $body['width'] ) : 0,
				'height'      => isset( $body['height'] ) ? absint( $body['height'] ) : 0,
				'extra'       => array(
					'channel' => isset( $body['author_name'] ) && is_string( $body['author_name'] ) ? self::clean_line( $body['author_name'] ) : '',
				),
			),
			'ok',
			'oembed'
		);
	}

	// Files ---------------------------------------------------------------------------------

	/**
	 * Details for a video file: from the media library when it's there, else
	 * read from the file on this server. Files elsewhere get what the page
	 * provides (poster, title) and nothing is downloaded.
	 *
	 * @param array $row Video row.
	 * @return void
	 */
	private static function fetch_file( array $row ) {
		$url        = $row['url'];
		$attachment = isset( $row['extra']['attachment_id'] ) ? absint( $row['extra']['attachment_id'] ) : 0;
		$path       = self::local_path( $url );

		if ( $attachment && 'attachment' !== get_post_type( $attachment ) ) {
			$attachment = 0;
		}

		if ( ! $attachment ) {
			$attachment = self::attachment_for( $url, $path );
		}

		$data = array(
			'extra' => array(
				'local'         => '' !== $path,
				'attachment_id' => $attachment,
			),
		);

		$meta = array();

		if ( $attachment ) {
			$meta = wp_get_attachment_metadata( $attachment );
			$meta = is_array( $meta ) ? $meta : array();

			$title = get_the_title( $attachment );
			$file  = get_attached_file( $attachment );
			$base  = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';

			// Titles WordPress made from the file name say nothing.
			if ( '' !== $title && ( '' === $base || ( sanitize_title( $title ) !== sanitize_title( $base ) ) ) ) {
				$data['title'] = self::clean_line( $title );
			}

			$attachment_post = get_post( $attachment );

			if ( $attachment_post ) {
				$text = '' !== trim( $attachment_post->post_content ) ? $attachment_post->post_content : $attachment_post->post_excerpt;

				$data['description'] = self::clean_text( wp_strip_all_tags( $text ) );
				$data['upload_date'] = $attachment_post->post_date_gmt && '0000-00-00 00:00:00' !== $attachment_post->post_date_gmt ? $attachment_post->post_date_gmt : null;
			}

			$thumb_id = (int) get_post_thumbnail_id( $attachment );

			if ( $thumb_id ) {
				$image = wp_get_attachment_image_src( $thumb_id, 'full' );

				if ( $image ) {
					$data['thumbnail']         = $image[0];
					$data['extra']['thumb_w'] = (int) $image[1];
					$data['extra']['thumb_h'] = (int) $image[2];
				}
			}
		}

		if ( empty( $meta['length'] ) && '' !== $path ) {
			if ( ! function_exists( 'wp_read_video_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}

			$read = function_exists( 'wp_read_video_metadata' ) ? wp_read_video_metadata( $path ) : false;
			$meta = is_array( $read ) ? $read : $meta;
		}

		$data['duration'] = ! empty( $meta['length'] ) ? absint( $meta['length'] ) : 0;
		$data['width']    = ! empty( $meta['width'] ) ? absint( $meta['width'] ) : 0;
		$data['height']   = ! empty( $meta['height'] ) ? absint( $meta['height'] ) : 0;

		// A poster set on the player beats the media library's video thumbnail.
		if ( ! empty( $row['extra']['poster'] ) ) {
			$data['thumbnail'] = $row['extra']['poster'];
			unset( $data['extra']['thumb_w'], $data['extra']['thumb_h'] );
		}

		self::save( $row, $data, 'ok', '' !== $path || $attachment ? 'file' : 'none' );
	}

	/**
	 * The file on this server behind a video URL, if it's in wp-content or the
	 * uploads folder and is a video file. Matches by path, so a CDN address
	 * for a file stored here still finds it.
	 *
	 * @param string $url Video URL.
	 * @return string Real path, or ''.
	 */
	public static function local_path( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path || ! preg_match( '/\.(?:' . Video_SEO_Detector::FILE_TYPES . ')$/i', $path ) ) {
			return '';
		}

		$path = rawurldecode( $path );

		// A null byte or control character is never a real file name (and a
		// null byte makes realpath() throw on PHP 8).
		if ( preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$roots   = array();

		if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$roots[] = array( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), $uploads['basedir'] );
		}

		$roots[] = array( (string) wp_parse_url( content_url(), PHP_URL_PATH ), WP_CONTENT_DIR );

		foreach ( $roots as $root ) {
			list( $url_path, $dir ) = $root;

			$url_path = untrailingslashit( $url_path );

			if ( '' === $url_path || 0 !== strpos( $path, $url_path . '/' ) ) {
				continue;
			}

			$real_dir = realpath( $dir );
			$file     = realpath( $dir . substr( $path, strlen( $url_path ) ) );

			if ( $real_dir && $file && 0 === strpos( $file, trailingslashit( $real_dir ) ) && is_file( $file ) && preg_match( '/\.(?:' . Video_SEO_Detector::FILE_TYPES . ')$/i', $file ) ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * The media library item for a video file.
	 *
	 * @param string $url  Video URL.
	 * @param string $path Local path ('' when not on this server).
	 * @return int Attachment ID, or 0.
	 */
	private static function attachment_for( $url, $path ) {
		$id = attachment_url_to_postid( preg_replace( '/[?#].*$/', '', $url ) );

		if ( ! $id && '' !== $path ) {
			$uploads = wp_get_upload_dir();
			$base    = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;

			if ( $base && 0 === strpos( $path, trailingslashit( $base ) ) ) {
				$id = attachment_url_to_postid( trailingslashit( $uploads['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( trailingslashit( $base ) ) ) ) );
			}
		}

		return (int) $id;
	}

	// Saving ------------------------------------------------------------------------------------

	/**
	 * Save fetched details and the video's resulting state.
	 *
	 * @param array  $row    Video row.
	 * @param array  $data   Details (title, description, thumbnail, duration, upload_date, width, height, views, extra).
	 * @param string $status ok|unavailable.
	 * @param string $source api|oembed|file|none.
	 * @param string $error  Message for unavailable videos.
	 * @return void
	 */
	private static function save( array $row, array $data, $status, $source, $error = '' ) {
		$extra = $row['extra'];

		if ( isset( $data['extra'] ) && is_array( $data['extra'] ) ) {
			$extra = array_merge( $extra, $data['extra'] );
		}

		$fields = array(
			'title'       => isset( $data['title'] ) ? $data['title'] : '',
			'description' => isset( $data['description'] ) ? $data['description'] : '',
			'thumbnail'   => isset( $data['thumbnail'] ) ? esc_url_raw( $data['thumbnail'] ) : '',
			'duration'    => isset( $data['duration'] ) ? (int) $data['duration'] : 0,
			'upload_date' => isset( $data['upload_date'] ) ? $data['upload_date'] : null,
			'width'       => isset( $data['width'] ) ? (int) $data['width'] : 0,
			'height'      => isset( $data['height'] ) ? (int) $data['height'] : 0,
			'views'       => isset( $data['views'] ) ? $data['views'] : null,
			'status'      => $status,
			'source'      => $source,
			'error'       => (string) $error,
			'extra'       => $extra,
			'attempts'    => 0,
			'next_try'    => null,
			'fetched_at'  => current_time( 'mysql', true ),
		);

		$state = Video_SEO_Video::row_state( array_merge( $row, $fields ) );

		$fields['state']   = $state['state'];
		$fields['missing'] = implode( ',', $state['missing'] );

		// Keep earlier details when a video turns unavailable, for the report.
		if ( 'unavailable' === $status && '' === $fields['title'] && ! empty( $row['title'] ) ) {
			unset( $fields['title'], $fields['description'], $fields['thumbnail'], $fields['duration'], $fields['upload_date'], $fields['width'], $fields['height'], $fields['views'] );
		}

		Video_SEO_DB::update( $row['id'], $fields );
		Video_SEO_Sitemap::bump();
	}

	/**
	 * Record an unexpected error while fetching, as a failed attempt.
	 *
	 * @param array[]   $rows Video rows.
	 * @param Throwable $e    Error.
	 * @return void
	 */
	private static function record_crash( array $rows, $e ) {
		foreach ( $rows as $row ) {
			$fresh = Video_SEO_DB::get_by_key( $row['video_key'] );

			if ( $fresh && in_array( $fresh['status'], array( 'pending', 'error' ), true ) ) {
				/* translators: %s: error message. */
				self::fail( $fresh, sprintf( __( 'Unexpected error: %s', 'video-seo' ), $e->getMessage() ) );
			}
		}
	}

	/**
	 * Record a failed fetch and back off: 15 minutes, doubling, then daily.
	 *
	 * @param array  $row     Video row.
	 * @param string $message Error.
	 * @return void
	 */
	private static function fail( array $row, $message ) {
		$attempts = (int) $row['attempts'] + 1;
		$wait     = $attempts >= self::MAX_ATTEMPTS ? DAY_IN_SECONDS : min( DAY_IN_SECONDS, 15 * MINUTE_IN_SECONDS * pow( 2, $attempts - 1 ) );
		$fields   = array(
			'status'   => 'error',
			'error'    => self::clean_line( $message, 250 ),
			'attempts' => $attempts,
			'next_try' => gmdate( 'Y-m-d H:i:s', time() + (int) $wait ),
		);

		// A video that had details keeps showing them while a refresh fails.
		if ( '' === (string) $row['fetched_at'] ) {
			$fields['state'] = 'error';
		}

		Video_SEO_DB::update( $row['id'], $fields );
	}

	// Helpers ------------------------------------------------------------------------------------

	/**
	 * Arguments for provider requests.
	 *
	 * @return array
	 */
	private static function request_args() {
		return array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 2,
			'user-agent'  => 'VideoSEO/' . VIDEO_SEO_VERSION . '; ' . home_url( '/' ),
			'headers'     => array( 'Accept' => 'application/json' ),
		);
	}

	/**
	 * Whether a string is a YouTube video ID.
	 *
	 * @param string $id ID.
	 * @return bool
	 */
	public static function is_youtube_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $id );
	}

	/**
	 * Whether a string looks like a Google API key.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_youtube_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_-]{30,60}$/', $key );
	}

	/**
	 * ISO 8601 duration (PT1H2M3S, P1DT2H) in seconds.
	 *
	 * @param string $iso Duration.
	 * @return int
	 */
	public static function iso_seconds( $iso ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', (string) $iso, $m ) ) {
			return 0;
		}

		$m = array_pad( $m, 5, 0 );

		return (int) $m[1] * DAY_IN_SECONDS + (int) $m[2] * HOUR_IN_SECONDS + (int) $m[3] * MINUTE_IN_SECONDS + (int) round( (float) $m[4] );
	}

	/**
	 * A provider's one-line text, made safe: no tags, no control characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Most characters.
	 * @return string
	 */
	public static function clean_line( $text, $max = 300 ) {
		$text = Video_SEO_Video::plain( (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		return self::cut( $text, $max );
	}

	/**
	 * A provider's multi-line text (a description), made safe.
	 *
	 * @param string $text Text.
	 * @param int    $max  Most characters.
	 * @return string
	 */
	public static function clean_text( $text, $max = 5000 ) {
		$text = Video_SEO_Video::plain( (string) $text );
		$text = preg_replace( "/[ \t]+\n/", "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );

		return self::cut( $text, $max );
	}

	/**
	 * Cut text to a length, multibyte-safe.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function cut( $text, $max ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max, 'UTF-8' ) ) : $text;
		}

		return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max ) ) : $text;
	}
}
