/**
 * Video SEO panel in the editor: the block editor's sidebar, or a box in the
 * classic editor. Shows each video on the page with its details and schema
 * status, the main-content check, key moments, and per-page changes.
 *
 * window.videoSeoEditor = { postId, path, settingsUrl, classic } comes from PHP.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.videoSeoEditor;

	if ( ! cfg || ! wp || ! wp.element || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var C = wp.components;

	// Lucide icons (ISC), drawn inline so they follow the text colour.
	var ICONS = {
		refresh: 'M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16M8 16H3v5',
		check: 'M21.801 10A10 10 0 1 1 17 3.335M9 11l3 3L22 4',
		alert: 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3M12 9v4M12 17h.01',
		info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
		bookmark: 'm19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z',
		pencil: 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497zM15 5l4 4',
		key: 'M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4zM16.5 7.5h.01',
		external: 'M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6',
		image: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM9 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM21 15l-3.086-3.086a2 2 0 0 0-2.828 0L6 21',
		video: 'm16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.47.47 0 0 0-.75-.398L16 10.5M4 6h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z'
	};

	function icon( name, size ) {
		return el(
			'svg',
			{
				className: 'vseo-i',
				width: size || 16,
				height: size || 16,
				viewBox: '0 0 24 24',
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 2,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
				'aria-hidden': true,
				focusable: false,
				style: { fill: 'none' }
			},
			el( 'path', { d: ICONS[ name ] } )
		);
	}

	// The content being edited, saved or not.
	function currentContent() {
		if ( ! cfg.classic ) {
			return wp.data.select( 'core/editor' ).getEditedPostContent();
		}

		var editor = window.tinymce && window.tinymce.get( 'content' );

		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}

		var textarea = document.getElementById( 'content' );

		return textarea ? textarea.value : '';
	}

	function chooseImage( onSelect ) {
		if ( ! wp.media ) {
			return;
		}

		var frame = wp.media( {
			title: __( 'Choose a thumbnail', 'video-seo' ),
			library: { type: 'image' },
			multiple: false,
			button: { text: __( 'Use as thumbnail', 'video-seo' ) }
		} );

		frame.on( 'select', function () {
			var image = frame.state().get( 'selection' ).first().toJSON();
			var sizes = image.sizes || {};
			var small = sizes.medium || sizes.large || sizes.full || image;

			onSelect( image.id, small.url );
		} );

		frame.open();
	}

	function statusTone( status ) {
		if ( 'ready' === status ) {
			return 'good';
		}

		if ( 'incomplete' === status || 'pending' === status || 'excluded' === status ) {
			return 'warn';
		}

		return 'bad';
	}

	// One video's edit form.
	function EditForm( props ) {
		var video = props.video;
		var draft = props.draft;
		var set = function ( field, value ) {
			var next = Object.assign( {}, draft );
			next[ field ] = value;
			props.onChange( next );
		};

		return el(
			'div',
			{ className: 'vseo-edit' },
			el( C.TextControl, {
				label: __( 'Title', 'video-seo' ),
				value: draft.title,
				placeholder: video.videoTitle || video.title,
				onChange: function ( value ) {
					set( 'title', value );
				},
				__nextHasNoMarginBottom: true
			} ),
			el( C.TextareaControl, {
				label: __( 'Description', 'video-seo' ),
				value: draft.description,
				placeholder: ( video.videoText || video.description || '' ).slice( 0, 240 ),
				help: __( 'Lines starting with a time, like "0:00 Intro", become key moments (three or more, starting at 0:00).', 'video-seo' ),
				rows: 5,
				onChange: function ( value ) {
					set( 'description', value );
				},
				__nextHasNoMarginBottom: true
			} ),
			el(
				'div',
				{ className: 'vseo-field' },
				el( 'span', { className: 'vseo-label' }, __( 'Thumbnail', 'video-seo' ) ),
				el(
					'div',
					{ className: 'vseo-thumb-pick' },
					el( 'img', { src: draft.thumbnail_url || video.thumbnail, alt: '' } ),
					el(
						'div',
						{ className: 'vseo-thumb-actions' },
						el(
							C.Button,
							{
								variant: 'secondary',
								size: 'small',
								isSmall: true,
								onClick: function () {
									chooseImage( function ( id, url ) {
										props.onChange( Object.assign( {}, draft, { thumbnail_id: id, thumbnail_url: url } ) );
									} );
								}
							},
							draft.thumbnail_id ? __( 'Replace image', 'video-seo' ) : __( 'Choose image', 'video-seo' )
						),
						draft.thumbnail_id ? el(
							C.Button,
							{
								variant: 'link',
								isDestructive: true,
								onClick: function () {
									props.onChange( Object.assign( {}, draft, { thumbnail_id: 0, thumbnail_url: '' } ) );
								}
							},
							__( 'Use the video\'s own', 'video-seo' )
						) : null
					)
				)
			),
			el( C.TextareaControl, {
				label: __( 'Transcript', 'video-seo' ),
				value: draft.transcript,
				help: __( 'Added to the schema. Paste the spoken words, e.g. from YouTube\'s "Show transcript".', 'video-seo' ),
				rows: 6,
				onChange: function ( value ) {
					set( 'transcript', value );
				},
				__nextHasNoMarginBottom: true
			} ),
			el( C.ToggleControl, {
				label: __( 'Show the transcript on the page', 'video-seo' ),
				checked: !! draft.show_transcript,
				disabled: ! draft.transcript,
				onChange: function ( value ) {
					set( 'show_transcript', value );
				},
				__nextHasNoMarginBottom: true
			} ),
			el( C.ToggleControl, {
				label: __( 'Leave out of schema and the sitemap', 'video-seo' ),
				checked: !! draft.exclude,
				onChange: function ( value ) {
					set( 'exclude', value );
				},
				__nextHasNoMarginBottom: true
			} ),
			el(
				'div',
				{ className: 'vseo-edit-actions' },
				el(
					C.Button,
					{ variant: 'primary', isBusy: props.saving, disabled: props.saving, onClick: props.onSave },
					__( 'Save video details', 'video-seo' )
				),
				el( C.Button, { variant: 'tertiary', disabled: props.saving, onClick: props.onCancel }, __( 'Cancel', 'video-seo' ) ),
				video.edited ? el(
					C.Button,
					{ variant: 'link', isDestructive: true, disabled: props.saving, onClick: props.onReset },
					__( 'Undo all changes', 'video-seo' )
				) : null
			)
		);
	}

	function VideoCard( props ) {
		var video = props.video;
		var tone = statusTone( video.status );
		var showKeyHint = 'youtube' === video.provider && ! props.apiKey && video.missing.length;

		return el(
			'div',
			{ className: 'vseo-card' },
			el(
				'div',
				{ className: 'vseo-thumb' },
				video.thumbnail ? el( 'img', { src: video.thumbnail, alt: '', loading: 'lazy' } ) : el( 'span', { className: 'vseo-thumb-empty' }, icon( 'video', 28 ) ),
				el( 'span', { className: 'vseo-provider vseo-provider--' + video.provider }, video.providerLabel ),
				video.duration ? el( 'span', { className: 'vseo-duration' }, video.duration ) : null
			),
			el( 'div', { className: 'vseo-title' }, video.title ),
			video.channel || video.uploaded ? el( 'div', { className: 'vseo-meta' }, [ video.channel, video.uploaded ].filter( Boolean ).join( ' · ' ) ) : null,
			el(
				'div',
				{ className: 'vseo-status is-' + tone },
				icon( 'good' === tone ? 'check' : ( 'bad' === tone ? 'alert' : 'info' ), 14 ),
				el( 'span', null, video.statusLabel ),
				video.edited ? el( 'span', { className: 'vseo-edited' }, icon( 'pencil', 12 ), __( 'Edited', 'video-seo' ) ) : null
			),
			video.missing.length && 'excluded' !== video.status ? el(
				'p',
				{ className: 'vseo-note' },
				/* translators: %s: list of missing details. */
				sprintf( __( 'Missing: %s', 'video-seo' ), video.missing.join( ', ' ) )
			) : null,
			video.error && ( 'unavailable' === video.status || 'error' === video.status ) ? el( 'p', { className: 'vseo-note is-bad' }, video.error ) : null,
			'no_thumbnail' === video.status ? el( 'p', { className: 'vseo-note' }, __( 'Google needs a thumbnail. Choose one under Edit details, set a poster on the video, or give the page a featured image.', 'video-seo' ) ) : null,
			showKeyHint ? el(
				'p',
				{ className: 'vseo-note vseo-hint' },
				icon( 'key', 13 ),
				el(
					'span',
					null,
					__( 'A free YouTube API key fills in the upload date, duration and description.', 'video-seo' ),
					cfg.settingsUrl ? ' ' : null,
					cfg.settingsUrl ? el( 'a', { href: cfg.settingsUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Add a key', 'video-seo' ) ) : null
				)
			) : null,
			video.clips.length ? el(
				'details',
				{ className: 'vseo-clips' },
				el(
					'summary',
					null,
					icon( 'bookmark', 14 ),
					/* translators: %d: number of key moments. */
					sprintf( _n( '%d key moment', '%d key moments', video.clips.length, 'video-seo' ), video.clips.length )
				),
				el(
					'ol',
					null,
					video.clips.map( function ( clip, i ) {
						return el( 'li', { key: i }, el( 'span', { className: 'vseo-clip-time' }, clip.time ), el( 'span', null, clip.name ) );
					} )
				)
			) : null,
			props.open ? el( EditForm, {
				video: video,
				draft: props.draft,
				saving: props.saving,
				onChange: props.onDraft,
				onSave: props.onSave,
				onCancel: props.onClose,
				onReset: props.onReset
			} ) : el(
				'div',
				{ className: 'vseo-card-actions' },
				el( C.Button, { variant: 'secondary', size: 'small', isSmall: true, onClick: props.onOpen }, icon( 'pencil', 14 ), __( 'Edit details', 'video-seo' ) ),
				video.url && 'file' !== video.provider ? el(
					C.Button,
					{ variant: 'tertiary', size: 'small', isSmall: true, href: video.url, target: '_blank', rel: 'noopener noreferrer' },
					/* translators: %s: YouTube or Vimeo. */
					sprintf( __( 'Open on %s', 'video-seo' ), video.providerLabel ),
					icon( 'external', 13 )
				) : null
			)
		);
	}

	function draftFrom( video ) {
		return Object.assign( {}, video.changes );
	}

	function App() {
		var dataState = useState( null );
		var data = dataState[ 0 ];
		var setData = dataState[ 1 ];
		var loadingState = useState( true );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var openState = useState( '' );
		var openKey = openState[ 0 ];
		var setOpenKey = openState[ 1 ];
		var draftState = useState( {} );
		var draft = draftState[ 0 ];
		var setDraft = draftState[ 1 ];
		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var setSaving = savingState[ 1 ];
		var mounted = useRef( true );

		function load() {
			setLoading( true );
			setError( '' );

			return wp.apiFetch( {
				path: cfg.path + '/videos',
				method: 'POST',
				data: { content: currentContent() }
			} ).then( function ( response ) {
				if ( mounted.current ) {
					setData( response );
					setLoading( false );
				}
			} ).catch( function ( err ) {
				if ( mounted.current ) {
					setError( ( err && err.message ) || __( 'Couldn\'t check the videos.', 'video-seo' ) );
					setLoading( false );
				}
			} );
		}

		function save( key, reset ) {
			setSaving( true );
			setError( '' );

			wp.apiFetch( {
				path: cfg.path + '/changes',
				method: 'POST',
				data: {
					key: key,
					reset: !! reset,
					changes: reset ? {} : draft,
					content: currentContent()
				}
			} ).then( function ( response ) {
				if ( mounted.current ) {
					setData( response );
					setSaving( false );
					setOpenKey( '' );
				}
			} ).catch( function ( err ) {
				if ( mounted.current ) {
					setError( ( err && err.message ) || __( 'Couldn\'t save the changes.', 'video-seo' ) );
					setSaving( false );
				}
			} );
		}

		useEffect( function () {
			mounted.current = true;
			load();

			// Check again after each save in the block editor.
			var unsubscribe = null;

			if ( ! cfg.classic && wp.data && wp.data.subscribe ) {
				var wasSaving = false;

				unsubscribe = wp.data.subscribe( function () {
					var editor = wp.data.select( 'core/editor' );
					var saving = editor.isSavingPost() && ! editor.isAutosavingPost();

					if ( wasSaving && ! saving ) {
						load();
					}

					wasSaving = saving;
				} );
			}

			return function () {
				mounted.current = false;

				if ( unsubscribe ) {
					unsubscribe();
				}
			};
		}, [] );

		var videos = data ? data.videos : [];
		var check = data ? data.check : null;

		return el(
			'div',
			{ className: 'vseo-app' },
			el(
				'div',
				{ className: 'vseo-head' },
				el(
					'span',
					{ className: 'vseo-count' },
					loading && ! data ? __( 'Looking for videos…', 'video-seo' )
						/* translators: %d: number of videos. */
						: sprintf( _n( '%d video on this page', '%d videos on this page', videos.length, 'video-seo' ), videos.length )
				),
				el(
					C.Button,
					{
						className: 'vseo-refresh',
						variant: 'tertiary',
						size: 'small',
						isSmall: true,
						disabled: loading,
						onClick: load,
						label: __( 'Check again', 'video-seo' ),
						showTooltip: true
					},
					loading ? el( C.Spinner ) : icon( 'refresh', 15 )
				)
			),
			error ? el( 'p', { className: 'vseo-note is-bad' }, error ) : null,
			data && ! data.schemaOn ? el( 'p', { className: 'vseo-note' }, __( 'Video schema is turned off in Video SEO\'s settings.', 'video-seo' ) ) : null,
			data && ! videos.length ? el( 'p', { className: 'vseo-muted' }, __( 'No videos yet. Embed a YouTube or Vimeo link, or add a Video block, and Video SEO adds the schema by itself.', 'video-seo' ) ) : null,
			check && videos.length ? ( check.flag ? el(
				'div',
				{ className: 'vseo-check is-warn' },
				el( 'strong', null, icon( 'alert', 15 ), __( 'Google may not show a video result', 'video-seo' ) ),
				check.reasons.map( function ( reason, i ) {
					return el( 'p', { key: i }, reason );
				} )
			) : el(
				'div',
				{ className: 'vseo-check is-good' },
				el( 'strong', null, icon( 'check', 15 ), __( 'The video is the page\'s main content', 'video-seo' ) )
			) ) : null,
			check && check.notes.length ? el( 'p', { className: 'vseo-muted' }, check.notes.join( ' ' ) ) : null,
			videos.map( function ( video ) {
				return el( VideoCard, {
					key: video.key,
					video: video,
					apiKey: data.apiKey,
					open: openKey === video.key,
					draft: openKey === video.key ? draft : null,
					saving: saving,
					onOpen: function () {
						setDraft( draftFrom( video ) );
						setOpenKey( video.key );
					},
					onClose: function () {
						setOpenKey( '' );
					},
					onDraft: setDraft,
					onSave: function () {
						save( video.key, false );
					},
					onReset: function () {
						save( video.key, true );
					}
				} );
			} )
		);
	}

	if ( cfg.classic ) {
		wp.domReady( function () {
			var root = document.getElementById( 'video-seo-classic' );

			if ( ! root ) {
				return;
			}

			if ( wp.element.createRoot ) {
				wp.element.createRoot( root ).render( el( App ) );
			} else {
				wp.element.render( el( App ), root );
			}
		} );

		return;
	}

	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! Panel || ! wp.plugins ) {
		return;
	}

	wp.plugins.registerPlugin( 'video-seo', {
		icon: icon( 'video', 20 ),
		render: function () {
			return el(
				Panel,
				{ name: 'video-seo', title: __( 'Video SEO', 'video-seo' ), className: 'vseo-panel' },
				el( App )
			);
		}
	} );
}( window.wp ) );
