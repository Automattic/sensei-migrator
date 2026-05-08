<?php
/**
 * PSR-4 style autoloader scoped to the Sensei_Migrator namespace.
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	const NAMESPACE_PREFIX = __NAMESPACE__ . '\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class ): void {
		if ( strpos( $class, self::NAMESPACE_PREFIX ) !== 0 ) {
			return;
		}

		$relative   = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
		$path_parts = explode( '\\', $relative );
		$short_name = array_pop( $path_parts );

		$dir = SENSEI_MIGRATOR_PATH . 'includes';
		foreach ( $path_parts as $part ) {
			$dir .= '/' . self::dir_segment( $part );
		}

		foreach ( self::candidate_filenames( $short_name ) as $filename ) {
			$candidate = $dir . '/' . $filename;
			if ( is_readable( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}

	private static function dir_segment( string $segment ): string {
		return strtolower( str_replace( '_', '-', $segment ) );
	}

	private static function candidate_filenames( string $short_name ): array {
		$slug = strtolower( str_replace( '_', '-', $short_name ) );
		return array(
			'class-' . $slug . '.php',
			'interface-' . $slug . '.php',
			'trait-' . $slug . '.php',
		);
	}
}
