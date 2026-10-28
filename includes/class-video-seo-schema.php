<?php
/**
 * VideoObject schema on pages with videos.
 *
 * With Yoast SEO, Rank Math or All in One SEO, the videos join that plugin's
 * schema graph (linked to its WebPage), and a VideoObject it already has for
 * the same video is filled in instead of repeated. Otherwise, or when the SEO
 * plugin's schema is off for the page, the plugin prints its own JSON-LD.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema output.
 */
final class Video_SEO_Schema {

	/**
	 * Whether an SEO plugin's graph took the videos on this request.
	 *
	 * @var bool
	 */
	private static $emitted = false;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'yoast' ), 20, 2 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'rank_math' ), 99, 2 );
		add_filter( 'aioseo_schema_output', array( __CLASS__, 'aioseo' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'standalone' ), 99 );
	}

	/**
	 * VideoObjects for the page being viewed.
	 *
	 * @return array[]
	 */
	public static function nodes() {
		if ( ! Video_SEO_Plugin::instance()->get_setting( 'schema' ) ) {
			return array();
		}

		$nodes = array();

		foreach ( Video_SEO_Frontend::page_videos() as $video ) {
			if ( Video_SEO_Video::is_listed( $video ) ) {
				$nodes[] = Video_SEO_Video::schema( $video );
			}
		}

		return $nodes;
	}

	/**
	 * Yoast SEO's graph.
	 *
	 * @param array $graph   Graph pieces.
	 * @param mixed $context Yoast's Meta_Tags_Context.
	 * @return array
	 */
	public static function yoast( $graph, $context = null ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		$page_id = is_object( $context ) && ! empty( $context->main_schema_id ) && is_string( $context->main_schema_id ) ? $context->main_schema_id : '';

		return self::merge( $graph, $page_id );
	}

	/**
	 * Rank Math's graph (an array keyed by name).
	 *
	 * @param array $data   Schema entities.
	 * @param mixed $jsonld Rank Math's JsonLD instance.
	 * @return array
	 */
	public static function rank_math( $data, $jsonld = null ) {
		return is_array( $data ) ? self::merge( $data, '' ) : $data;
	}

	/**
	 * All in One SEO's graph.
	 *
	 * @param array $graph Graph.
	 * @return array
	 */
	public static function aioseo( $graph ) {
		return is_array( $graph ) ? self::merge( $graph, '' ) : $graph;
	}

	/**
	 * Add the page's videos to a graph.
	 *
	 * @param array  $graph   Graph (a list, or Rank Math's keyed array).
	 * @param string $page_id The WebPage @id, if the SEO plugin told us.
	 * @return array
	 */
	public static function merge( array $graph, $page_id ) {
		$nodes = self::nodes();

		if ( ! $nodes ) {
			return $graph;
		}

		self::$emitted = true;

		if ( '' === $page_id ) {
			$page_id = self::find_page_id( $graph );
		}

		// VideoObjects the SEO plugin made itself, by the video they describe.
		$existing = array();

		foreach ( $graph as $name => $node ) {
			if ( is_array( $node ) && self::is_type( $node, 'VideoObject' ) ) {
				$key = self::node_key( $node );

				if ( '' !== $key ) {
					$existing[ $key ] = $name;
				}
			}
		}

		$list  = ! $graph || array_keys( $graph ) === range( 0, count( $graph ) - 1 );
		$added = 0;

		foreach ( $nodes as $node ) {
			$key = self::node_key( $node );

			if ( '' !== $key && isset( $existing[ $key ] ) ) {
				// Fill in what theirs lacks rather than describing the video twice.
				$name           = $existing[ $key ];
				$graph[ $name ] = $graph[ $name ] + array_diff_key( $node, array_flip( array( '@id', '@type' ) ) );
				continue;
			}

			if ( '' !== $page_id ) {
				$node['isPartOf'] = array( '@id' => $page_id );
			}

			if ( $list ) {
				$graph[] = $node;
			} else {
				++$added;
				$graph[ 'video-seo-' . $added ] = $node;
			}
		}

		return $graph;
	}

	/**
	 * The page's WebPage @id in a graph.
	 *
	 * @param array $graph Graph.
	 * @return string
	 */
	private static function find_page_id( array $graph ) {
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) || empty( $node['@id'] ) || ! is_string( $node['@id'] ) || empty( $node['@type'] ) ) {
				continue;
			}

			foreach ( (array) $node['@type'] as $type ) {
				if ( is_string( $type ) && ( 'WebPage' === $type || 'Page' === substr( $type, -4 ) ) ) {
					return $node['@id'];
				}
			}
		}

		return '';
	}

	/**
	 * Whether a node has a type.
	 *
	 * @param array  $node Node.
	 * @param string $type Type.
	 * @return bool
	 */
	private static function is_type( array $node, $type ) {
		return isset( $node['@type'] ) && in_array( $type, (array) $node['@type'], true );
	}

	/**
	 * Which video a VideoObject describes, from its embed or file address.
	 *
	 * @param array $node VideoObject.
	 * @return string Video key, or ''.
	 */
	private static function node_key( array $node ) {
		foreach ( array( 'embedUrl', 'contentUrl', 'url' ) as $field ) {
			if ( empty( $node[ $field ] ) || ! is_string( $node[ $field ] ) ) {
				continue;
			}

			$video = Video_SEO_Detector::from_url( $node[ $field ] );

			if ( $video ) {
				return $video['key'];
			}
		}

		return '';
	}

	/**
	 * Print the videos as JSON-LD when no SEO plugin's graph took them.
	 *
	 * @return void
	 */
	public static function standalone() {
		if ( self::$emitted ) {
			return;
		}

		$nodes = self::nodes();

		if ( ! $nodes ) {
			return;
		}

		self::$emitted = true;

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);

		if ( $json ) {
			echo "\n<script type=\"application/ld+json\" class=\"video-seo-schema\">" . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < > & hex-escaped.
		}
	}
}
