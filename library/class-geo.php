<?php
/**
 * Forminator Geo
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Forminator_Geo
 *
 * Handle geo-location data
 *
 * @since 1.0
 */
class Forminator_Geo {

	/**
	 * Check whether an IP address falls inside a CIDR range
	 *
	 * Supports IPv4 and IPv6. A range without a prefix is a single address.
	 *
	 * @since 1.57.3
	 * @param string $ip - the ip to check.
	 * @param string $range - the range in CIDR notation. Eg 127.0.0.0/24 or 2400:cb00::/32.
	 *
	 * @return bool
	 */
	private static function ip_in_range( $ip, $range ) {
		if ( ! is_string( $range ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$parts  = explode( '/', $range, 2 );
		$subnet = trim( $parts[0] );
		if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		// IPv4 and IPv6 never match each other, they are 4 and 16 bytes wide.
		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$max_prefix = strlen( $ip_bin ) * 8;
		$prefix     = isset( $parts[1] ) ? trim( $parts[1] ) : $max_prefix;
		if ( ! is_numeric( $prefix ) || $prefix < 0 || $prefix > $max_prefix ) {
			return false;
		}

		$bytes = intdiv( (int) $prefix, 8 );
		$bits  = (int) $prefix % 8;

		if ( $bytes && 0 !== strncmp( $ip_bin, $subnet_bin, $bytes ) ) {
			return false;
		}
		if ( ! $bits ) {
			return true;
		}

		$mask = chr( ( 0xFF << ( 8 - $bits ) ) & 0xFF );

		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	/**
	 * Validates that the IP that made the request is from cloudflare
	 *
	 * @since 1.0
	 * @param string $ip - the ip to check.
	 *
	 * @return bool
	 */
	private static function validate_cloudflare_ip( $ip ) {
		$cloudflare_ips = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);

		/**
		 * Filter the Cloudflare ranges allowed to set the CF-Connecting-IP header
		 *
		 * @param array $cloudflare_ips IP ranges in CIDR notation.
		 */
		$cloudflare_ips = apply_filters( 'forminator_cloudflare_ip_ranges', $cloudflare_ips );

		foreach ( (array) $cloudflare_ips as $cloudflare_ip ) {
			if ( self::ip_in_range( $ip, $cloudflare_ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an address may set the client IP forwarding headers
	 *
	 * Configure public proxies with the FORMINATOR_TRUSTED_PROXIES constant - a
	 * comma separated list of IPs or CIDR ranges - or the forminator_trusted_proxies
	 * filter. Private and reserved addresses are trusted by default since requests
	 * from those were relayed by local infrastructure, never straight from the web.
	 *
	 * @since 1.57.3
	 * @param string $ip - the ip to check.
	 *
	 * @return bool
	 */
	private static function is_trusted_proxy( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$trusted_proxies = defined( 'FORMINATOR_TRUSTED_PROXIES' ) && is_string( FORMINATOR_TRUSTED_PROXIES )
			? array_map( 'trim', explode( ',', FORMINATOR_TRUSTED_PROXIES ) )
			: array();

		/**
		 * Filter the reverse proxies allowed to set the client IP forwarding headers
		 *
		 * @param array  $trusted_proxies IPs or CIDR ranges. Empty by default.
		 * @param string $ip              The address being checked.
		 */
		$trusted_proxies = apply_filters( 'forminator_trusted_proxies', $trusted_proxies, $ip );

		foreach ( (array) $trusted_proxies as $trusted_proxy ) {
			if ( self::ip_in_range( $ip, $trusted_proxy ) ) {
				return true;
			}
		}

		$is_local = ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

		/**
		 * Filter whether private and reserved addresses count as trusted proxies
		 *
		 * @param bool   $is_local True when the address is private or reserved.
		 * @param string $ip       The address being checked.
		 */
		return (bool) apply_filters( 'forminator_trust_private_proxies', $is_local, $ip );
	}

	/**
	 * Get the client IP forwarded by a trusted proxy
	 *
	 * X-Forwarded-For grows left to right, so it is walked from the right dropping
	 * our own proxies until the first address we did not append ourselves.
	 *
	 * @since 1.57.3
	 * @return string Empty when no usable address was forwarded.
	 */
	private static function get_forwarded_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forward  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$chain    = array_reverse( array_map( 'trim', explode( ',', $forward ) ) );
			$last_hop = '';

			foreach ( $chain as $hop ) {
				// Drop the port from the IPv4:port and [IPv6]:port forms.
				if ( preg_match( '/^\[(.+)\](?::\d+)?$/', $hop, $matches ) ) {
					$hop = $matches[1];
				} elseif ( 1 === substr_count( $hop, ':' ) ) {
					$hop = strstr( $hop, ':', true );
				}

				// An unreadable hop breaks the chain, everything left of it is unverified.
				if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
					return '';
				}

				if ( ! self::is_trusted_proxy( $hop ) ) {
					return $hop;
				}

				$last_hop = $hop;
			}

			// A chain of proxies only, so the client is on our network. Keep the
			// leftmost hop to tell those visitors apart.
			return $last_hop;
		}

		foreach ( array( 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * A shorhand function to get user IP
	 *
	 * REMOTE_ADDR is used unless the host that opened the connection is a Cloudflare
	 * edge or a trusted proxy, in which case the address it forwarded is used.
	 *
	 * @since 1.0
	 * @return mixed|string
	 */
	public static function get_user_ip() {
		$remote  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_ip = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::validate_cloudflare_ip( $user_ip ) ) {
			$cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
				$user_ip = $cf_ip;
			}
		} elseif ( self::is_trusted_proxy( $user_ip ) ) {
			$forwarded_ip = self::get_forwarded_ip();
			if ( $forwarded_ip ) {
				$user_ip = $forwarded_ip;
			}
		}

		return apply_filters( 'forminator_user_ip', $user_ip );
	}
}
