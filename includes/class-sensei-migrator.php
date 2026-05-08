<?php
/**
 * Main plugin bootstrap.
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator;

use Sensei_Migrator\CLI\CLI_Command;
use Sensei_Migrator\Core\Source_Registry;
use Sensei_Migrator\Sources\LearnDash\LearnDash_Source_Adapter;

defined( 'ABSPATH' ) || exit;

class Sensei_Migrator {

	private static ?Source_Registry $registry = null;

	public static function init(): void {
		if ( ! self::sensei_is_compatible() ) {
			add_action( 'admin_notices', array( self::class, 'render_sensei_dependency_notice' ) );
			return;
		}

		self::$registry = self::build_registry();
		CLI_Command::register();
	}

	public static function registry(): ?Source_Registry {
		return self::$registry;
	}

	private static function build_registry(): Source_Registry {
		$registry = new Source_Registry();
		$registry->register( new LearnDash_Source_Adapter() );

		/**
		 * Fires after built-in source adapters have been registered. Plugins providing
		 * additional source adapters should hook here to register them.
		 *
		 * @param Source_Registry $registry The shared adapter registry.
		 */
		do_action( 'sensei_migrator_register_sources', $registry );

		return $registry;
	}

	public static function on_activation(): void {
		if ( ! self::sensei_is_compatible() ) {
			deactivate_plugins( plugin_basename( SENSEI_MIGRATOR_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: minimum required Sensei version */
						__( 'Sensei Migrator requires Sensei LMS %s or later. Please install or update Sensei before activating this plugin.', 'sensei-migrator' ),
						SENSEI_MIGRATOR_MIN_SENSEI_VERSION
					)
				),
				esc_html__( 'Sensei Migrator', 'sensei-migrator' ),
				array( 'back_link' => true )
			);
		}
	}

	public static function on_deactivation(): void {
	}

	private static function sensei_is_compatible(): bool {
		if ( ! defined( 'SENSEI_LMS_VERSION' ) ) {
			return false;
		}

		return version_compare( SENSEI_LMS_VERSION, SENSEI_MIGRATOR_MIN_SENSEI_VERSION, '>=' );
	}

	public static function render_sensei_dependency_notice(): void {
		$message = defined( 'SENSEI_LMS_VERSION' )
			? sprintf(
				/* translators: 1: required Sensei version, 2: detected Sensei version */
				esc_html__( 'Sensei Migrator requires Sensei LMS %1$s or later. Detected version: %2$s.', 'sensei-migrator' ),
				esc_html( SENSEI_MIGRATOR_MIN_SENSEI_VERSION ),
				esc_html( SENSEI_LMS_VERSION )
			)
			: esc_html__( 'Sensei Migrator requires Sensei LMS to be installed and activated.', 'sensei-migrator' );

		printf( '<div class="notice notice-error"><p>%s</p></div>', $message );
	}
}
