<?php
/**
 * A video as it appears on one page: the provider's details, the editor's
 * per-page changes and the page's own fallbacks, merged. Also builds the
 * VideoObject schema, key moments and the main-content check.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video model.
 */
final class Video_SEO_Video {

	/**
	 * Post meta holding per-page changes, keyed by video key.
	 */
	const OVERRIDES_META = '_vseo_overrides';

	/**
	 * Most per-page changes kept for one post.
	 */
	const MAX_OVERRIDES = 50;

	/**
	 * Words of text before the first video that make it look secondary.
	 */
	const LATE_WORDS = 300;

	/**
	 * Words on a page that make it an article with a video, not a video page.
	 */
	const LONG_WORDS = 2000;

	/**
	 * Make plain text (a video description, a title typed in the editor) safe
	 * for JSON-LD, XML and the page without losing words.
	 *
	 * Real tags and comments go, then any "<" that could still open one
	 * ("</", "<!", "<?", "<" + letter). So "</script" and "<!--" can't appear
	 * in any output, while "Subscribe <3" and "a < b" keep their text, which
	 * strip_tags() would cut off at the "<".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		// Bad bytes first, so removing them can't join "<" and "/" later. Invalid
		// UTF-8 is repaired too: it would make an SEO plugin's json_encode()
		// drop its whole schema graph.
		$text = (string) $text;

		if ( '' !== $text && ! preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : wp_check_invalid_utf8( $text, true );
		}

		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		$text = preg_replace( '#<!--.*?-->|<!\[CDATA\[.*?\]\]>|<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $text );
		$text = preg_replace( '#</?[a-z][a-z0-9:-]*(?:\s[^<>]*)?/?>#i', '', $text );

		// The whole run of "<", so "<<!--" can't become "<!--".
		return (string) preg_replace( '#<+(?=[/!?a-z])#i', '', $text );
	}

	/**
	 * State and missing details of a video row, for the report. Ignores
	 * per-page changes (those are shown in the editor).
	 *
	 * @param array $row Video row.
	 * @return array{state:string,missing:string[]}
	 */
	public static function row_state( array $row ) {
		$fetched = ! empty( $row['fetched_at'] );

		if ( 'unavailable' === $row['status'] ) {
			return array(
				'state'   => 'unavailable',
				'missing' => array(),
			);
		}

		if ( ! $fetched && in_array( $row['status'], array( 'pending', 'error' ), true ) ) {
			return array(
				'state'   => $row['status'],
				'missing' => array(),
			);
		}

		$missing = array();

		if ( '' === (string) $row['thumbnail'] ) {
			$missing[] = 'thumbnail';
		}

		if ( '' === (string) $row['description'] ) {
			$missing[] = 'description';
		}

		if ( empty( $row['duration'] ) && ( empty( $row['extra']['live'] ) || 'none' === $row['extra']['live'] ) ) {
			$missing[] = 'duration';
		}

		if ( empty( $row['upload_date'] ) && 'file' !== $row['provider'] ) {
			$missing[] = 'upload_date';
		}

		return array(
			'state'   => $missing ? 'incomplete' : 'ready',
			'missing' => $missing,
		);
	}

	/**
	 * A post's per-page changes.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array>
	 */
	public static function overrides( $post_id ) {
		$saved = get_post_meta( $post_id, self::OVERRIDES_META, true );
		$out   = array();

		if ( is_array( $saved ) ) {
			foreach ( $saved as $key => $values ) {
				if ( Video_SEO_Detector::is_key( $key ) && is_array( $values ) ) {
					$out[ $key ] = $values;
				}
			}
		}

		return $out;
	}

	/**
	 * Clean per-page changes from the editor.
	 *
	 * @param array $in Raw values.
	 * @return array Only the fields that are set.
	 */
	public static function sanitize_override( array $in ) {
		$out = array();

		if ( isset( $in['title'] ) && is_scalar( $in['title'] ) ) {
			$out['title'] = Video_SEO_Fetcher::clean_line( $in['title'], 150 );
		}

		if ( isset( $in['description'] ) && is_scalar( $in['description'] ) ) {
			$out['description'] = Video_SEO_Fetcher::clean_text( $in['description'], 5000 );
		}

		if ( isset( $in['transcript'] ) && is_scalar( $in['transcript'] ) ) {
			$out['transcript'] = Video_SEO_Fetcher::clean_text( $in['transcript'], 100000 );
		}

		if ( isset( $in['thumbnail_id'] ) ) {
			$id = absint( $in['thumbnail_id'] );

			if ( $id && wp_attachment_is_image( $id ) ) {
				$out['thumbnail_id'] = $id;
			}
		}

		foreach ( array( 'show_transcript', 'exclude' ) as $flag ) {
			if ( ! empty( $in[ $flag ] ) && 'false' !== $in[ $flag ] ) {
				$out[ $flag ] = 1;
			}
		}

		return array_filter(
			$out,
			function ( $value ) {
				return '' !== $value && 0 !== $value;
			}
		);
	}

	/**
	 * The video as it appears on a page.
	 *
	 * @param array   $row       Video row.
	 * @param WP_Post $post      Page.
	 * @param int     $index     Position on the page (0 = first).
	 * @param array   $overrides The page's changes (null to load them).
	 * @return array
	 */
	public static function build( array $row, WP_Post $post, $index = 0, $overrides = null ) {
		$plugin    = Video_SEO_Plugin::instance();
		$overrides = null === $overrides ? self::overrides( $post->ID ) : $overrides;
		$change    = isset( $overrides[ $row['video_key'] ] ) ? $overrides[ $row['video_key'] ] : array();
		$provider  = $row['provider'];
		$fetched   = ! empty( $row['fetched_at'] );
		$missing   = array();
		$sources   = array();

		// Title.
		if ( ! empty( $change['title'] ) ) {
			$title            = $change['title'];
			$sources['title'] = 'edited';
		} elseif ( '' !== (string) $row['title'] ) {
			$title            = $row['title'];
			$sources['title'] = 'video';
		} else {
			$title            = self::plain( get_the_title( $post ) );
			$sources['title'] = 'page';
		}

		// Description.
		if ( ! empty( $change['description'] ) ) {
			$description            = $change['description'];
			$sources['description'] = 'edited';
		} elseif ( '' !== (string) $row['description'] ) {
			$description            = $row['description'];
			$sources['description'] = 'video';
		} else {
			$description            = self::page_summary( $post );
			$sources['description'] = 'page';
			$missing[]              = 'description';

			if ( '' === $description ) {
				$description = $title;
			}
		}

		// Thumbnail.
		$thumbnail = '';

		if ( ! empty( $change['thumbnail_id'] ) ) {
			$thumbnail            = (string) wp_get_attachment_image_url( (int) $change['thumbnail_id'], 'full' );
			$sources['thumbnail'] = 'edited';
		}

		if ( '' === $thumbnail && '' !== (string) $row['thumbnail'] ) {
			$thumbnail            = $row['thumbnail'];
			$sources['thumbnail'] = 'video';
		}

		if ( '' === $thumbnail && 'youtube' === $provider ) {
			// YouTube always has this size, even before details arrive.
			$thumbnail            = 'https://i.ytimg.com/vi/' . $row['provider_id'] . '/hqdefault.jpg';
			$sources['thumbnail'] = 'video';
		}

		if ( '' === $thumbnail && 'file' === $provider && $plugin->get_setting( 'featured_fallback' ) && has_post_thumbnail( $post ) ) {
			$thumbnail            = (string) get_the_post_thumbnail_url( $post, 'full' );
			$sources['thumbnail'] = 'page';
		}

		// Upload date: the video's own, else the day it was published here.
		if ( ! empty( $row['upload_date'] ) ) {
			$uploaded = $row['upload_date'];
		} else {
			$uploaded = '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : current_time( 'mysql', true );

			if ( 'file' !== $provider ) {
				$missing[] = 'upload_date';
			}
		}

		$live = ! empty( $row['extra']['live'] ) && 'none' !== $row['extra']['live'];

		if ( empty( $row['duration'] ) && ! $live ) {
			$missing[] = 'duration';
		}

		// Status.
		if ( ! empty( $change['exclude'] ) ) {
			$status = 'excluded';
		} elseif ( 'unavailable' === $row['status'] ) {
			$status = 'unavailable';
		} elseif ( ! $fetched && in_array( $row['status'], array( 'pending', 'error' ), true ) ) {
			$status = $row['status'];
		} elseif ( '' === $thumbnail ) {
			$status = 'no_thumbnail';
		} else {
			$status = in_array( 'description', $missing, true ) || in_array( 'duration', $missing, true ) || in_array( 'upload_date', $missing, true ) ? 'incomplete' : 'ready';
		}

		$hash      = isset( $row['extra']['hash'] ) ? (string) $row['extra']['hash'] : '';
		$embed     = '';
		$content   = '';
		$permalink = (string) get_permalink( $post );

		if ( 'youtube' === $provider ) {
			$embed = 'https://www.youtube.com/embed/' . $row['provider_id'];
		} elseif ( 'vimeo' === $provider ) {
			$embed = 'https://player.vimeo.com/video/' . $row['provider_id'] . ( '' !== $hash ? '?h=' . $hash : '' );
		} else {
			$content = $row['url'];
		}

		$video = array(
			'key'             => $row['video_key'],
			'id'              => (int) $row['id'],
			'provider'        => $provider,
			'provider_id'     => $row['provider_id'],
			'index'           => (int) $index,
			'anchor'          => self::anchor( $row['video_key'] ),
			'title'           => self::plain( $title ),
			'description'     => self::plain( $description ),
			'thumbnail'       => $thumbnail,
			'upload_date'     => $uploaded,
			'duration'        => (int) $row['duration'],
			'width'           => (int) $row['width'],
			'height'          => (int) $row['height'],
			'views'           => $row['views'],
			'embed_url'       => $embed,
			'content_url'     => $content,
			'watch_url'       => $row['url'],
			'channel'         => isset( $row['extra']['channel'] ) ? (string) $row['extra']['channel'] : '',
			'status'          => $status,
			'missing'         => array_values( array_unique( $missing ) ),
			'sources'         => $sources,
			'error'           => (string) $row['error'],
			'fetched'         => $fetched,
			'transcript'      => isset( $change['transcript'] ) ? (string) $change['transcript'] : '',
			'show_transcript' => ! empty( $change['show_transcript'] ),
			'changes'         => $change,
			'clips'           => array(),
			'page_url'        => $permalink,
		);

		if ( $plugin->get_setting( 'key_moments' ) && $permalink ) {
			$video['clips'] = self::clips( $video['description'], $video['duration'], $permalink, $video['index'] );
		}

		/**
		 * Filters a video as it appears on a page, before schema, sitemap and
		 * the editor panel use it.
		 *
		 * @param array   $video Video.
		 * @param array   $row   Stored video row.
		 * @param WP_Post $post  Page.
		 */
		return (array) apply_filters( 'video_seo_video', $video, $row, $post );
	}

	/**
	 * Every video on a page, built.
	 *
	 * @param WP_Post $post Page.
	 * @return array[]
	 */
	public static function for_post( WP_Post $post ) {
		$rows      = Video_SEO_DB::post_videos( $post->ID );
		$overrides = self::overrides( $post->ID );
		$out       = array();

		foreach ( $rows as $index => $row ) {
			$out[] = self::build( $row, $post, $index, $overrides );
		}

		return $out;
	}

	/**
	 * Whether a video goes into schema and the sitemap.
	 *
	 * @param array $video Built video.
	 * @return bool
	 */
	public static function is_listed( array $video ) {
		return in_array( $video['status'], array( 'ready', 'incomplete' ), true );
	}

	/**
	 * A short summary of the page, for videos without a description.
	 *
	 * @param WP_Post $post Page.
	 * @return string
	 */
	private static function page_summary( WP_Post $post ) {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		$text = strip_shortcodes( $text );
		$text = preg_replace( '#\bhttps?://\S+#i', ' ', self::plain( $text ) );
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );

		if ( '' === $text ) {
			return '';
		}

		$short = Video_SEO_Fetcher::cut( $text, 300 );

		return $short === $text ? $text : preg_replace( '/\s+\S*$/u', '', $short ) . '…';
	}

	/**
	 * The fragment used in a video's schema @id.
	 *
	 * @param string $key Video key.
	 * @return string
	 */
	public static function anchor( $key ) {
		if ( 0 === strpos( $key, 'file:' ) ) {
			return 'video-file-' . substr( $key, 5, 10 );
		}

		return 'video-' . str_replace( ':', '-', $key );
	}

	// Schema ----------------------------------------------------------------------------------

	/**
	 * VideoObject for a built video.
	 *
	 * @param array $video Built video.
	 * @return array
	 */
	public static function schema( array $video ) {
		$node = array(
			'@type'        => 'VideoObject',
			'@id'          => $video['page_url'] . '#' . $video['anchor'],
			'name'         => $video['title'],
			'description'  => $video['description'],
			'thumbnailUrl' => array( $video['thumbnail'] ),
			'uploadDate'   => self::iso_date( $video['upload_date'] ),
		);

		if ( $video['duration'] > 0 ) {
			$node['duration'] = self::iso_duration( $video['duration'] );
		}

		if ( '' !== $video['embed_url'] ) {
			$node['embedUrl'] = $video['embed_url'];
		}

		if ( '' !== $video['content_url'] ) {
			$node['contentUrl'] = $video['content_url'];
		}

		if ( null !== $video['views'] ) {
			$node['interactionStatistic'] = array(
				'@type'                => 'InteractionCounter',
				'interactionType'      => array( '@type' => 'WatchAction' ),
				'userInteractionCount' => (int) $video['views'],
			);
		}

		if ( '' !== $video['transcript'] ) {
			$node['transcript'] = $video['transcript'];
		}

		if ( $video['clips'] ) {
			$node['hasPart'] = array();

			foreach ( $video['clips'] as $clip ) {
				$part = array(
					'@type'       => 'Clip',
					'name'        => $clip['name'],
					'startOffset' => $clip['start'],
					'url'         => $clip['url'],
				);

				if ( null !== $clip['end'] ) {
					$part['endOffset'] = $clip['end'];
				}

				$node['hasPart'][] = $part;
			}
		}

		/**
		 * Filters a VideoObject before it's printed or added to an SEO
		 * plugin's schema.
		 *
		 * @param array $node  VideoObject.
		 * @param array $video Built video.
		 */
		return (array) apply_filters( 'video_seo_schema_node', $node, $video );
	}

	/**
	 * GMT MySQL date as ISO 8601 with a time zone.
	 *
	 * @param string $gmt GMT date.
	 * @return string
	 */
	public static function iso_date( $gmt ) {
		$time = strtotime( $gmt . ' UTC' );

		return gmdate( 'Y-m-d\TH:i:sP', $time ? $time : time() );
	}

	/**
	 * Seconds as an ISO 8601 duration (PT1H2M3S).
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function iso_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs    = $seconds % 60;
		$out     = 'PT';

		if ( $hours ) {
			$out .= $hours . 'H';
		}

		if ( $minutes ) {
			$out .= $minutes . 'M';
		}

		if ( $secs || 'PT' === $out ) {
			$out .= $secs . 'S';
		}

		return $out;
	}

	/**
	 * Seconds as a clock (4:05, 1:02:03).
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function clock( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs    = $seconds % 60;

		return $hours ? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs ) : sprintf( '%d:%02d', $minutes, $secs );
	}

	// Key moments -------------------------------------------------------------------------------

	/**
	 * Chapters written as timestamps in a description ("0:00 Intro").
	 *
	 * Follows YouTube's chapter rules so stray times in the text aren't read
	 * as chapters: the list starts at 0:00, has at least three entries, and
	 * runs in order.
	 *
	 * @param string $text     Description.
	 * @param int    $duration Video length in seconds (0 = unknown).
	 * @return array[] {start, name}
	 */
	public static function chapters( $text, $duration = 0 ) {
		$time  = '[\(\[]?((?:\d{1,2}:)?\d{1,2}:[0-5]\d)[\)\]]?';
		$sep   = '(?:\s*[-–—:|•·.)]\s*|\s+)';
		$found = array();

		foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
			$line = preg_replace( '/^[\s*•·\-–—]+|[\s*•·\-–—]+$/u', '', $line );

			if ( preg_match( '/^' . $time . $sep . '(.+)$/u', $line, $m ) ) {
				$stamp = $m[1];
				$name  = $m[2];
			} elseif ( preg_match( '/^(.+?)' . $sep . $time . '$/u', $line, $m ) ) {
				$stamp = $m[2];
				$name  = $m[1];
			} else {
				continue;
			}

			$name = (string) preg_replace( '/^[\s\-–—:|•·.]+|[\s\-–—:|•·.]+$/u', '', $name );

			if ( '' === $name ) {
				continue;
			}

			$parts   = array_reverse( array_map( 'intval', explode( ':', $stamp ) ) );
			$seconds = $parts[0] + ( isset( $parts[1] ) ? $parts[1] * 60 : 0 ) + ( isset( $parts[2] ) ? $parts[2] * 3600 : 0 );

			$found[] = array(
				'start' => $seconds,
				'name'  => Video_SEO_Fetcher::cut( $name, 100 ),
			);
		}

		// Take the in-order run that starts at 0:00.
		$run = array();

		foreach ( $found as $chapter ) {
			if ( ! $run ) {
				if ( 0 === $chapter['start'] ) {
					$run[] = $chapter;
				}
				continue;
			}

			$last = end( $run );

			if ( $chapter['start'] <= $last['start'] || ( $duration > 0 && $chapter['start'] >= $duration ) ) {
				break;
			}

			$run[] = $chapter;
		}

		return count( $run ) >= 3 ? $run : array();
	}

	/**
	 * Key moments (Clip markup) from a description's chapters.
	 *
	 * Each links to the page with ?t=seconds; the front end starts the video
	 * there. Videos after the first also carry their position (vseo_video=2).
	 *
	 * @param string $text      Description.
	 * @param int    $duration  Length in seconds.
	 * @param string $permalink Page URL.
	 * @param int    $index     Video's position on the page.
	 * @return array[] {name, start, end, url}
	 */
	public static function clips( $text, $duration, $permalink, $index ) {
		$chapters = self::chapters( $text, $duration );
		$out      = array();
		$count    = count( $chapters );

		foreach ( $chapters as $i => $chapter ) {
			$args = array( 't' => $chapter['start'] );

			if ( $index > 0 ) {
				$args = array_merge( array( 'vseo_video' => $index + 1 ), $args );
			}

			if ( $i + 1 < $count ) {
				$end = $chapters[ $i + 1 ]['start'];
			} else {
				$end = $duration > $chapter['start'] ? (int) $duration : null;
			}

			$out[] = array(
				'name'  => $chapter['name'],
				'start' => $chapter['start'],
				'end'   => $end,
				'url'   => add_query_arg( $args, $permalink ),
			);
		}

		return $out;
	}

	// Main content check ---------------------------------------------------------------------------

	/**
	 * Whether Google is likely to treat the video as the page's main content.
	 *
	 * Since late 2023 Google only shows a video result (video mode, the Videos
	 * tab, a thumbnail in web results) when the video is the main thing on
	 * the page, not an extra beside an article.
	 *
	 * @param array $scan Scan summary: words, before, count.
	 * @return array{flag:bool,reasons:string[],notes:string[]}
	 */
	public static function focus_check( array $scan ) {
		$words   = isset( $scan['words'] ) ? (int) $scan['words'] : 0;
		$before  = isset( $scan['before'] ) ? (int) $scan['before'] : -1;
		$count   = isset( $scan['count'] ) ? (int) $scan['count'] : 0;
		$reasons = array();
		$notes   = array();

		if ( $count < 1 ) {
			return array(
				'flag'    => false,
				'reasons' => array(),
				'notes'   => array(),
			);
		}

		if ( $before > self::LATE_WORDS && $before > $words / 3 ) {
			$reasons[] = sprintf(
				/* translators: %s: number of words. */
				_n( 'The first video comes after %s word of text. Move it near the top if it\'s what the page is about.', 'The first video comes after %s words of text. Move it near the top if it\'s what the page is about.', $before, 'video-seo' ),
				number_format_i18n( $before )
			);
		}

		if ( $words > self::LONG_WORDS ) {
			$reasons[] = sprintf(
				/* translators: %s: number of words. */
				__( 'The page has %s words of text, so the video reads as an extra beside an article.', 'video-seo' ),
				number_format_i18n( $words )
			);
		}

		if ( $count > 1 ) {
			$notes[] = sprintf(
				/* translators: %d: number of videos. */
				__( '%d videos on this page. Google shows a video result for one at most, normally the first.', 'video-seo' ),
				$count
			);
		}

		return array(
			'flag'    => (bool) $reasons,
			'reasons' => $reasons,
			'notes'   => $notes,
		);
	}

	/**
	 * Labels for missing details.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public static function missing_label( $field ) {
		$labels = array(
			'description' => __( 'description', 'video-seo' ),
			'duration'    => __( 'duration', 'video-seo' ),
			'upload_date' => __( 'upload date', 'video-seo' ),
			'thumbnail'   => __( 'thumbnail', 'video-seo' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Provider labels.
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	public static function provider_label( $provider ) {
		$labels = array(
			'youtube' => 'YouTube',
			'vimeo'   => 'Vimeo',
			'file'    => __( 'Video file', 'video-seo' ),
		);

		return isset( $labels[ $provider ] ) ? $labels[ $provider ] : $provider;
	}
}
