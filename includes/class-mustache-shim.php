<?php
/**
 * Bundled Mustache_Engine — fallback when bobthecow/mustache.php isn't available
 * via Composer's vendor/ folder.
 *
 * Provides the subset of Mustache features the plugin's templates actually use:
 *   - {{variable}}             (HTML-escaped)
 *   - {{{variable}}}           (raw)
 *   - {{nested.field}}
 *   - {{#section}}...{{/section}}  (truthy branch / list iteration)
 *   - {{^section}}...{{/section}}  (inverted/falsy branch)
 *
 * This is NOT a Mustache spec-conforming implementation. It's a focused
 * 100-line implementation built to render the plugin's seed templates correctly
 * without requiring a Composer install on the host. Operators who run
 * `composer install --no-dev` get the real bobthecow/mustache.php library
 * loaded by Composer's autoloader, which takes precedence due to the
 * class_exists() guard below.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

if ( ! class_exists( 'Mustache_Engine' ) ) {
	class Mustache_Engine {
		public function __construct( array $opts = array() ) {}

		public function render( string $template, array $data ): string {
			return $this->renderScope( $template, array( $data ) );
		}

		private function renderScope( string $template, array $scopes ): string {
			// Sections / inverted sections, nested-aware.
			while ( preg_match( '/\{\{([#^])([a-zA-Z0-9_.]+)\}\}/', $template, $m, PREG_OFFSET_CAPTURE ) ) {
				$type     = $m[1][0];
				$name     = $m[2][0];
				$open_pos = (int) $m[0][1];
				$open_len = strlen( $m[0][0] );

				$depth     = 1;
				$cursor    = $open_pos + $open_len;
				$close_pos = null;
				$close_len = 0;
				while ( preg_match( '/\{\{([#^\/])' . preg_quote( $name, '/' ) . '\}\}/', $template, $mc, PREG_OFFSET_CAPTURE, $cursor ) ) {
					$t   = $mc[1][0];
					$pos = (int) $mc[0][1];
					$len = strlen( $mc[0][0] );
					if ( '/' === $t ) {
						$depth--;
						if ( 0 === $depth ) {
							$close_pos = $pos;
							$close_len = $len;
							break;
						}
					} else {
						$depth++;
					}
					$cursor = $pos + $len;
				}
				if ( null === $close_pos ) {
					break;
				}

				$inner = substr( $template, $open_pos + $open_len, $close_pos - ( $open_pos + $open_len ) );
				$value = $this->lookup( $name, $scopes );
				$rendered = '';

				if ( '#' === $type ) {
					if ( is_array( $value ) && array_is_list( $value ) ) {
						foreach ( $value as $item ) {
							$new_scope = is_array( $item ) ? $item : array( '.' => $item );
							$rendered .= $this->renderScope( $inner, array_merge( $scopes, array( $new_scope ) ) );
						}
					} elseif ( ! empty( $value ) ) {
						$new_scopes = is_array( $value ) ? array_merge( $scopes, array( $value ) ) : $scopes;
						$rendered = $this->renderScope( $inner, $new_scopes );
					}
				} else { // '^'
					if ( empty( $value ) || ( is_array( $value ) && array_is_list( $value ) && 0 === count( $value ) ) ) {
						$rendered = $this->renderScope( $inner, $scopes );
					}
				}

				$template = substr( $template, 0, $open_pos ) . $rendered . substr( $template, $close_pos + $close_len );
			}

			// Variables.
			$template = preg_replace_callback( '/\{\{(\{?)([a-zA-Z0-9_.]+)\}?\}\}/', function ( $m ) use ( $scopes ) {
				$value = $this->lookup( $m[2], $scopes );
				if ( is_bool( $value ) ) {
					return $value ? '1' : '';
				}
				if ( null === $value || is_array( $value ) ) {
					return '';
				}
				$str = (string) $value;
				return '{' === $m[1] ? $str : htmlspecialchars( $str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
			}, $template );

			return (string) $template;
		}

		private function lookup( string $dotted_name, array $scopes ) {
			$keys = explode( '.', $dotted_name );
			foreach ( array_reverse( $scopes ) as $scope ) {
				if ( ! is_array( $scope ) ) {
					continue;
				}
				$cursor = $scope;
				$found  = true;
				foreach ( $keys as $k ) {
					if ( is_array( $cursor ) && array_key_exists( $k, $cursor ) ) {
						$cursor = $cursor[ $k ];
					} else {
						$found = false;
						break;
					}
				}
				if ( $found ) {
					return $cursor;
				}
			}
			return null;
		}
	}
}
