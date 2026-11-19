<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SSP_Plugin {
	private static $instance;
	private $option_name = 'ssp_settings';
	private $player_rendered = false;

	/**
	 * Twitch autoplay notes.
	 *
	 * Twitch documents a 400x300 minimum for autoplay, but in practice a plain
	 * 16:9 iframe at its real size (e.g. 352x198) autoplays fine. What breaks
	 * autoplay is shrinking the player with a CSS transform, fading it, or
	 * drawing anything on top of it. So no size floor is enforced by default.
	 *
	 * If Twitch ever enforces the floor, set TWITCH_MIN_WIDTH 400,
	 * TWITCH_MIN_HEIGHT 300 and TWITCH_MIN_ZOOM 0.6: the player is then drawn at
	 * 400x300 and shown smaller with CSS zoom (never transform), cropped to 16:9.
	 */
	const TWITCH_MIN_WIDTH  = 240;
	const TWITCH_MIN_HEIGHT = 0;
	const TWITCH_MIN_ZOOM   = 1;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		// Warm the provider connections before the iframe asks for them.
		add_action( 'wp_head', array( $this, 'resource_hints' ), 2 );
		// Render at the very top of <body> so the iframe starts loading at parse time.
		add_action( 'wp_body_open', array( $this, 'render_player' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_player' ), 10 );
	}

	public function maybe_upgrade() {
		if ( version_compare( get_option( 'ssp_version', '0' ), SSP_VERSION, '>=' ) ) {
			return;
		}
		$settings = get_option( $this->option_name, array() );
		$settings = $this->sanitize_settings( wp_parse_args( is_array( $settings ) ? $settings : array(), $this->defaults() ) );
		update_option( $this->option_name, $settings );
		update_option( 'ssp_version', SSP_VERSION );
	}

	private function defaults() {
		return array(
			'enabled'          => 1,
			'stream_url'       => '',
			'autoplay'         => 1,
			'muted'            => 1,
			'border_color'     => '#ef4444',
			'border_width'     => 3,
			'border_style'     => 'solid',
			'border_radius'    => 12,
			'desktop_width'    => 352,
			'mobile_width'     => 92,
			'bottom_offset'    => 20,
			'side_offset'      => 20,
			'offline_message'  => 'The stream is offline right now. Check back soon!',
			'custom_embed_url' => '',
		);
	}

	private function saved_settings() {
		$saved = get_option( $this->option_name, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	private function settings() {
		/**
		 * Filter the player settings for the current request.
		 *
		 * @param array $settings Saved settings merged with defaults.
		 */
		return apply_filters( 'ssp_settings', $this->saved_settings() );
	}

	public function admin_menu() {
		add_menu_page(
			__( 'Featured Stream Slot', 'featured-stream-slot' ),
			__( 'Featured Stream', 'featured-stream-slot' ),
			'manage_options',
			'sticky-stream-player',
			array( $this, 'settings_page' ),
			'dashicons-video-alt3',
			81
		);
	}

	public function register_settings() {
		register_setting(
			'ssp_settings_group',
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$defaults = $this->defaults();
		$input    = is_array( $input ) ? $input : array();
		$output   = array();
		$str      = static function ( $value ) {
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};
		$output['enabled']          = empty( $input['enabled'] ) ? 0 : 1;
		$output['autoplay']         = empty( $input['autoplay'] ) ? 0 : 1;
		$output['muted']            = empty( $input['muted'] ) ? 0 : 1;
		$output['stream_url']       = esc_url_raw( $str( $input['stream_url'] ?? '' ) );
		$output['custom_embed_url'] = esc_url_raw( $str( $input['custom_embed_url'] ?? '' ) );
		$output['offline_message']  = sanitize_textarea_field( is_scalar( $input['offline_message'] ?? '' ) ? (string) ( $input['offline_message'] ?? '' ) : '' );

		$color                   = sanitize_hex_color( $str( $input['border_color'] ?? '' ) );
		$output['border_color']  = $color ? $color : $defaults['border_color'];
		$styles                  = array( 'solid', 'double', 'dashed', 'dotted', 'none' );
		$output['border_style']  = in_array( $input['border_style'] ?? '', $styles, true ) ? $input['border_style'] : 'solid';
		$output['border_width']  = $this->bounded_int( $input['border_width'] ?? $defaults['border_width'], 0, 12 );
		$output['border_radius'] = $this->bounded_int( $input['border_radius'] ?? $defaults['border_radius'], 0, 40 );
		$output['desktop_width'] = $this->bounded_int( $input['desktop_width'] ?? $defaults['desktop_width'], 240, 900 );
		$output['mobile_width']  = $this->bounded_int( $input['mobile_width'] ?? $defaults['mobile_width'], 60, 100 );
		$output['bottom_offset'] = $this->bounded_int( $input['bottom_offset'] ?? $defaults['bottom_offset'], 0, 200 );
		$output['side_offset']   = $this->bounded_int( $input['side_offset'] ?? $defaults['side_offset'], 0, 200 );
		return $output;
	}

	private function bounded_int( $value, $min, $max ) {
		return max( $min, min( $max, absint( $value ) ) );
	}

	public function admin_assets( $hook ) {
		if ( 'toplevel_page_sticky-stream-player' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ssp-admin', SSP_URL . 'assets/admin.css', array(), SSP_VERSION );
		wp_enqueue_script( 'ssp-admin', SSP_URL . 'assets/admin.js', array(), SSP_VERSION, true );
	}

	public function frontend_assets() {
		if ( is_admin() || ! $this->should_render() ) {
			return;
		}
		// The stylesheet is also inlined next to the player so it is styled even
		// when an optimiser defers this file; the file keeps it cacheable.
		wp_enqueue_style( 'ssp-player', SSP_URL . 'assets/player.css', array(), SSP_VERSION );
		wp_enqueue_script( 'ssp-player', SSP_URL . 'assets/player.js', array(), SSP_VERSION, true );
	}

	/**
	 * Whether the player has something to show on this request.
	 */
	private function should_render() {
		$s = $this->settings();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		$embed = $this->resolve_embed( $s );
		$show  = ( '' !== $embed['url'] ) || ( '' !== trim( (string) $s['offline_message'] ) );
		/**
		 * Filter whether the player is shown on the current page.
		 * Return false to hide it, for example on checkout or landing pages.
		 *
		 * @param bool  $show     Whether the player will render.
		 * @param array $settings Current settings.
		 */
		return (bool) apply_filters( 'ssp_should_render', $show, $s );
	}

	/**
	 * Start DNS/TCP/TLS handshakes during head parse so the iframe request
	 * written into <body> can go straight to the stream.
	 */
	public function resource_hints() {
		if ( is_admin() || ! $this->should_render() ) {
			return;
		}
		$embed = $this->resolve_embed( $this->settings() );
		if ( '' === $embed['url'] ) {
			return;
		}
		// Player documents load in an iframe (credentialed request), so they get a
		// plain preconnect; the CDN/API hosts the player fetches from get crossorigin.
		$documents = array();
		$cors      = array();
		switch ( $embed['provider'] ) {
			case 'twitch':
				$documents = array( 'https://player.twitch.tv', 'https://clips.twitch.tv' );
				$cors      = array( 'https://static.twitchcdn.net', 'https://usher.ttvnw.net', 'https://gql.twitch.tv' );
				break;
			case 'youtube':
				$documents = array( 'https://www.youtube.com' );
				$cors      = array( 'https://i.ytimg.com', 'https://www.google.com' );
				break;
			case 'kick':
				$documents = array( 'https://player.kick.com' );
				break;
			case 'vimeo':
				$documents = array( 'https://player.vimeo.com' );
				$cors      = array( 'https://i.vimeocdn.com', 'https://f.vimeocdn.com' );
				break;
			default:
				$host = wp_parse_url( $embed['url'], PHP_URL_HOST );
				if ( $host ) {
					$documents = array( 'https://' . $host );
				}
		}
		if ( 'twitch' === $embed['provider'] && false === strpos( $embed['url'], 'clips.twitch.tv' ) ) {
			$documents = array( 'https://player.twitch.tv' );
		}
		foreach ( $documents as $host ) {
			printf( '<link rel="preconnect" href="%s">' . "\n", esc_attr( esc_url( $host ) ) );
		}
		foreach ( $cors as $host ) {
			printf( '<link rel="preconnect" href="%s" crossorigin>' . "\n", esc_attr( esc_url( $host ) ) );
		}
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->saved_settings();
		?>
		<div class="wrap ssp-admin">
			<h1><?php esc_html_e( 'Featured Stream Slot', 'featured-stream-slot' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Paste a Twitch, YouTube, Kick, or Vimeo link. The plugin converts supported links into safe embed URLs automatically.', 'featured-stream-slot' ); ?></p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'ssp_settings_group' ); ?>
				<div class="ssp-grid">
					<section class="ssp-card">
						<h2><?php esc_html_e( 'Stream', 'featured-stream-slot' ); ?></h2>
						<?php $this->checkbox( 'enabled', $s, __( 'Show the sticky player', 'featured-stream-slot' ) ); ?>
						<label for="ssp-stream-url"><?php esc_html_e( 'Stream or video link', 'featured-stream-slot' ); ?></label>
						<input id="ssp-stream-url" class="regular-text" type="url" name="ssp_settings[stream_url]" value="<?php echo esc_attr( $s['stream_url'] ); ?>" placeholder="https://twitch.tv/channel or https://youtube.com/watch?v=...">
						<p class="description"><?php esc_html_e( 'Supported: Twitch channels, videos and clips, YouTube videos, live links and playlists, Kick channels, and Vimeo videos.', 'featured-stream-slot' ); ?></p>
						<?php $this->checkbox( 'autoplay', $s, __( 'Autoplay when the page loads (always muted; browsers block autoplay with sound)', 'featured-stream-slot' ) ); ?>
						<?php $this->checkbox( 'muted', $s, __( 'Start muted even when autoplay is off', 'featured-stream-slot' ) ); ?>
						<label for="ssp-custom-url"><?php esc_html_e( 'Custom iframe embed URL (advanced fallback)', 'featured-stream-slot' ); ?></label>
						<input id="ssp-custom-url" class="regular-text" type="url" name="ssp_settings[custom_embed_url]" value="<?php echo esc_attr( $s['custom_embed_url'] ); ?>" placeholder="https://trusted-provider.example/embed/...">
						<p class="description"><?php esc_html_e( 'Used only when the main link is blank or unsupported. Only enter an HTTPS URL from a provider you trust.', 'featured-stream-slot' ); ?></p>
					</section>

					<section class="ssp-card">
						<h2><?php esc_html_e( 'Appearance & position', 'featured-stream-slot' ); ?></h2>
						<label for="ssp-border-color"><?php esc_html_e( 'Border color', 'featured-stream-slot' ); ?></label>
						<div class="ssp-color-row">
							<input id="ssp-border-color" type="color" name="ssp_settings[border_color]" value="<?php echo esc_attr( $s['border_color'] ); ?>">
							<div class="ssp-swatches" aria-label="<?php esc_attr_e( 'Color presets', 'featured-stream-slot' ); ?>">
								<?php foreach ( array( '#ef4444', '#7c3aed', '#3b82f6', '#22c55e', '#f59e0b', '#ffffff', '#111827' ) as $color ) : ?>
									<button type="button" class="ssp-swatch" data-color="<?php echo esc_attr( $color ); ?>" style="--swatch:<?php echo esc_attr( $color ); ?>" aria-label="<?php echo esc_attr( $color ); ?>"></button>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="ssp-fields">
							<?php $this->number( 'border_width', $s, __( 'Border width', 'featured-stream-slot' ), 0, 12, 'px' ); ?>
							<?php $this->number( 'border_radius', $s, __( 'Corner radius', 'featured-stream-slot' ), 0, 40, 'px' ); ?>
							<?php $this->number( 'desktop_width', $s, __( 'Desktop width', 'featured-stream-slot' ), 240, 900, 'px' ); ?>
							<?php $this->number( 'mobile_width', $s, __( 'Mobile width', 'featured-stream-slot' ), 60, 100, '%' ); ?>
							<?php $this->number( 'bottom_offset', $s, __( 'Bottom offset', 'featured-stream-slot' ), 0, 200, 'px' ); ?>
							<?php $this->number( 'side_offset', $s, __( 'Desktop side offset', 'featured-stream-slot' ), 0, 200, 'px' ); ?>
						</div>
						<label for="ssp-border-style"><?php esc_html_e( 'Border style', 'featured-stream-slot' ); ?></label>
						<select id="ssp-border-style" name="ssp_settings[border_style]">
							<?php foreach ( array( 'solid', 'double', 'dashed', 'dotted', 'none' ) as $style ) : ?>
								<option value="<?php echo esc_attr( $style ); ?>" <?php selected( $s['border_style'], $style ); ?>><?php echo esc_html( ucfirst( $style ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</section>

					<section class="ssp-card">
						<h2><?php esc_html_e( 'Offline message', 'featured-stream-slot' ); ?></h2>
						<label for="ssp-offline-message"><?php esc_html_e( 'Text shown inside the player when the stream is offline or no link is set', 'featured-stream-slot' ); ?></label>
						<textarea id="ssp-offline-message" class="large-text" rows="3" name="ssp_settings[offline_message]"><?php echo esc_textarea( $s['offline_message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leave this blank to hide the player completely while the stream is offline. Offline detection works for Twitch channels; other providers show their own offline screen.', 'featured-stream-slot' ); ?></p>
					</section>
				</div>
				<?php submit_button(); ?>
			</form>
			<div class="ssp-note"><strong><?php esc_html_e( 'Visitor controls:', 'featured-stream-slot' ); ?></strong> <?php esc_html_e( 'Drag with the top bar, resize from the grip in the top-left corner, reset with ↺, or close with ×. Size and position are remembered in that browser; a closed player stays closed until the visitor opens a new tab.', 'featured-stream-slot' ); ?></div>
		</div>
		<?php
	}

	private function checkbox( $key, $settings, $label ) {
		printf(
			'<label class="ssp-check"><input type="checkbox" name="ssp_settings[%1$s]" value="1" %2$s> %3$s</label>',
			esc_attr( $key ),
			checked( ! empty( $settings[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	private function number( $key, $settings, $label, $min, $max, $unit ) {
		printf(
			'<label class="ssp-number"><span>%1$s</span><span><input type="number" name="ssp_settings[%2$s]" value="%3$d" min="%4$d" max="%5$d"> %6$s</span></label>',
			esc_html( $label ),
			esc_attr( $key ),
			(int) $settings[ $key ],
			(int) $min,
			(int) $max,
			esc_html( $unit )
		);
	}

	public function render_player() {
		if ( is_admin() || $this->player_rendered ) {
			return;
		}
		if ( ! $this->should_render() ) {
			return;
		}
		$s               = $this->settings();
		$embed           = $this->resolve_embed( $s );
		$offline_message = trim( (string) $s['offline_message'] );
		$this->player_rendered = true;

		$provider      = $embed['provider'];
		$is_twitch     = 'twitch' === $provider;
		$desktop_width = (int) $s['desktop_width'];
		$inner_width   = $desktop_width;
		$zoom          = 1;
		if ( $is_twitch && $desktop_width < self::TWITCH_MIN_WIDTH ) {
			$inner_width = self::TWITCH_MIN_WIDTH;
			$zoom        = max( self::TWITCH_MIN_ZOOM, $desktop_width / self::TWITCH_MIN_WIDTH );
		}
		$style = sprintf(
			'--ssp-border:%1$s;--ssp-border-width:%2$dpx;--ssp-border-style:%3$s;--ssp-radius:%4$dpx;--ssp-inner-width:%5$dpx;--ssp-zoom:%6$s;--ssp-mobile-width:%7$dvw;--ssp-bottom:%8$dpx;--ssp-side:%9$dpx;--ssp-min-height:%10$dpx;',
			esc_attr( $s['border_color'] ),
			(int) $s['border_width'],
			esc_attr( $s['border_style'] ),
			(int) $s['border_radius'],
			$inner_width,
			rtrim( rtrim( number_format( $zoom, 4, '.', '' ), '0' ), '.' ),
			(int) $s['mobile_width'],
			(int) $s['bottom_offset'],
			(int) $s['side_offset'],
			$is_twitch ? self::TWITCH_MIN_HEIGHT : 0
		);
		$classes = array( 'ssp-player', 'ssp-provider-' . $provider, 'ssp-floating' );
		if ( '' === $embed['url'] ) {
			$classes[] = 'ssp-is-offline';
		}
		$critical_css_path = plugin_dir_path( SSP_FILE ) . 'assets/player.css';
		$critical_css      = is_readable( $critical_css_path ) ? file_get_contents( $critical_css_path ) : '';
		$allow             = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
		?>
		<?php if ( $critical_css ) : ?>
			<style id="ssp-player-critical-css"><?php echo str_ireplace( '</style', '<\/style', $critical_css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bundled stylesheet. ?></style>
		<?php endif; ?>
		<aside id="ssp-player" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $style ); ?>" data-ssp-provider="<?php echo esc_attr( $provider ); ?>" data-ssp-autoplay="<?php echo $s['autoplay'] ? '1' : '0'; ?>" data-ssp-muted="<?php echo ( $s['muted'] || $s['autoplay'] ) ? '1' : '0'; ?>" data-ssp-min-width="<?php echo $is_twitch ? (int) self::TWITCH_MIN_WIDTH : 240; ?>" data-ssp-min-zoom="<?php echo $is_twitch ? esc_attr( (string) self::TWITCH_MIN_ZOOM ) : '1'; ?>" aria-label="<?php esc_attr_e( 'Streaming video player', 'featured-stream-slot' ); ?>">
			<div class="ssp-window">
				<div class="ssp-toolbar">
					<span class="ssp-resize" data-ssp-resize title="<?php esc_attr_e( 'Resize player', 'featured-stream-slot' ); ?>" aria-hidden="true"></span>
					<span class="ssp-drag-zone" data-ssp-drag-handle title="<?php esc_attr_e( 'Drag player', 'featured-stream-slot' ); ?>"><span class="ssp-grip" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Drag player', 'featured-stream-slot' ); ?></span></span>
					<button class="ssp-btn ssp-reset" type="button" data-ssp-reset title="<?php esc_attr_e( 'Reset size and position', 'featured-stream-slot' ); ?>" aria-label="<?php esc_attr_e( 'Reset size and position', 'featured-stream-slot' ); ?>">&#8634;</button>
					<button class="ssp-btn ssp-close" type="button" data-ssp-close title="<?php esc_attr_e( 'Close player', 'featured-stream-slot' ); ?>" aria-label="<?php esc_attr_e( 'Close player', 'featured-stream-slot' ); ?>">&times;</button>
				</div>
				<div class="ssp-media">
					<?php if ( '' !== $embed['url'] ) : ?>
						<iframe id="ssp-embed" src="<?php echo esc_url( $embed['url'] ); ?>" title="<?php esc_attr_e( 'Live stream', 'featured-stream-slot' ); ?>" allow="<?php echo esc_attr( $allow ); ?>" allowfullscreen loading="eager" referrerpolicy="strict-origin-when-cross-origin"></iframe>
					<?php endif; ?>
					<?php if ( '' !== $offline_message ) : ?>
						<div class="ssp-offline" data-ssp-offline<?php echo '' === $embed['url'] ? '' : ' hidden'; ?>><p><?php echo nl2br( esc_html( $offline_message ) ); ?></p></div>
					<?php endif; ?>
				</div>
			</div>
		</aside>
		<script>(function(){try{if(sessionStorage.getItem('ssp-closed')){var p=document.getElementById('ssp-player');if(p){p.parentNode.removeChild(p);}}}catch(e){}})();</script>
		<?php
	}

	/**
	 * Turn the saved link into an embed URL.
	 *
	 * @return array { url: string, provider: string }
	 */
	private function resolve_embed( $s ) {
		$url      = $this->get_embed_url( (string) $s['stream_url'], $s );
		$provider = $this->provider_for( (string) $s['stream_url'] );
		if ( '' === $url && ! empty( $s['custom_embed_url'] ) && 0 === strpos( $s['custom_embed_url'], 'https://' ) ) {
			$url      = $s['custom_embed_url'];
			$provider = $this->provider_for( $url );
			if ( 'other' === $provider ) {
				$provider = 'custom';
			}
		}
		return array( 'url' => $url, 'provider' => $url ? $provider : 'none' );
	}

	private function provider_for( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( preg_match( '/(^|\.)twitch\.tv$/', $host ) ) {
			return 'twitch';
		}
		if ( preg_match( '/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host ) ) {
			return 'youtube';
		}
		if ( preg_match( '/(^|\.)kick\.com$/', $host ) ) {
			return 'kick';
		}
		if ( preg_match( '/(^|\.)vimeo\.com$/', $host ) ) {
			return 'vimeo';
		}
		return 'other';
	}

	private function get_embed_url( $url, $s ) {
		if ( ! $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
		$path = trim( $parts['path'] ?? '', '/' );
		parse_str( $parts['query'] ?? '', $query );
		$query    = array_filter( $query, 'is_string' );
		$autoplay = ! empty( $s['autoplay'] );
		$muted    = $autoplay || ! empty( $s['muted'] );
		$parent   = $this->twitch_parents();

		if ( in_array( $host, array( 'youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com' ), true ) ) {
			$id = '';
			if ( 'youtu.be' === $host ) {
				$id = explode( '/', $path )[0] ?? '';
			} elseif ( isset( $query['v'] ) ) {
				$id = $query['v'];
			} elseif ( preg_match( '#^(?:embed|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $m ) ) {
				$id = $m[1];
			}
			$params = array(
				'autoplay'    => $autoplay ? '1' : '0',
				'mute'        => $muted ? '1' : '0',
				'playsinline' => '1',
				'controls'    => '1',
				'enablejsapi' => '1',
				'rel'         => '0',
				'origin'      => home_url(),
			);
			if ( ! empty( $query['list'] ) ) {
				$params['list'] = sanitize_text_field( $query['list'] );
			}
			if ( $id && preg_match( '/^[A-Za-z0-9_-]{6,}$/', $id ) ) {
				return 'https://www.youtube.com/embed/' . rawurlencode( $id ) . '?' . http_build_query( $params );
			}
			if ( ! empty( $query['list'] ) ) {
				return 'https://www.youtube.com/embed/videoseries?' . http_build_query( $params );
			}
			return '';
		}

		if ( 'clips.twitch.tv' === $host ) {
			$slug = explode( '/', $path )[0] ?? '';
			if ( $slug && preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
				return $this->with_parents( 'https://clips.twitch.tv/embed?' . http_build_query( array(
					'clip'     => $slug,
					'parent'   => '__SSP_PARENT__',
					'autoplay' => $autoplay ? 'true' : 'false',
					'muted'    => $muted ? 'true' : 'false',
				) ), $parent );
			}
			return '';
		}

		if ( in_array( $host, array( 'twitch.tv', 'm.twitch.tv', 'player.twitch.tv' ), true ) ) {
			$segments = explode( '/', $path );
			$params   = array();
			if ( 'player.twitch.tv' === $host && ! empty( $query['channel'] ) ) {
				$params['channel'] = sanitize_key( $query['channel'] );
			} elseif ( 'player.twitch.tv' === $host && ! empty( $query['video'] ) && preg_match( '/^v?\d+$/', $query['video'] ) ) {
				$params['video'] = $query['video'];
			} elseif ( isset( $segments[0], $segments[1] ) && 'videos' === $segments[0] && ctype_digit( $segments[1] ) ) {
				$params['video'] = 'v' . $segments[1];
			} elseif ( isset( $segments[0], $segments[1], $segments[2] ) && 'clip' === $segments[1] && preg_match( '/^[A-Za-z0-9_-]+$/', $segments[2] ) ) {
				return $this->with_parents( 'https://clips.twitch.tv/embed?' . http_build_query( array(
					'clip'     => $segments[2],
					'parent'   => '__SSP_PARENT__',
					'autoplay' => $autoplay ? 'true' : 'false',
					'muted'    => $muted ? 'true' : 'false',
				) ), $parent );
			} elseif ( ! empty( $segments[0] ) && preg_match( '/^[A-Za-z0-9_]+$/', $segments[0] ) ) {
				$params['channel'] = $segments[0];
			}
			if ( isset( $params['channel'] ) || isset( $params['video'] ) ) {
				$params['parent']   = '__SSP_PARENT__';
				$params['autoplay'] = $autoplay ? 'true' : 'false';
				$params['muted']    = $muted ? 'true' : 'false';
				return $this->with_parents( 'https://player.twitch.tv/?' . http_build_query( $params ), $parent );
			}
			return '';
		}

		if ( in_array( $host, array( 'kick.com', 'player.kick.com' ), true ) ) {
			$channel = explode( '/', $path )[0] ?? '';
			if ( $channel && preg_match( '/^[A-Za-z0-9_-]+$/', $channel ) ) {
				return 'https://player.kick.com/' . rawurlencode( $channel ) . '?' . http_build_query(
					array(
						'autoplay' => $autoplay ? 'true' : 'false',
						'muted'    => $muted ? 'true' : 'false',
					)
				);
			}
			return '';
		}

		if ( in_array( $host, array( 'vimeo.com', 'player.vimeo.com' ), true ) && preg_match( '#^(?:video/)?(\d+)(?:/([A-Za-z0-9]+))?#', $path, $m ) ) {
			$vparams = array( 'autoplay' => $autoplay ? '1' : '0', 'muted' => $muted ? '1' : '0', 'playsinline' => '1' );
			$hash    = $m[2] ?? ( $query['h'] ?? '' );
			if ( $hash && preg_match( '/^[A-Za-z0-9]+$/', $hash ) ) {
				$vparams['h'] = $hash;
			}
			return 'https://player.vimeo.com/video/' . $m[1] . '?' . http_build_query( $vparams );
		}
		return '';
	}

	/**
	 * Hostnames Twitch should accept as the embedding page (apex and www).
	 *
	 * @return string[]
	 */
	private function twitch_parents() {
		$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$apex    = preg_replace( '/^www\./', '', $host );
		$parents = array_unique( array_filter( array( $host, $apex, $apex ? 'www.' . $apex : '' ) ) );
		return array_values( $parents );
	}

	/**
	 * Twitch allows several parent= values, which http_build_query() cannot express.
	 */
	private function with_parents( $url, $parents ) {
		$pairs = array();
		foreach ( (array) $parents as $p ) {
			$pairs[] = 'parent=' . rawurlencode( $p );
		}
		return str_replace( 'parent=__SSP_PARENT__', implode( '&', $pairs ), $url );
	}
}
