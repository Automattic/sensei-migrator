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

	/**
	 * Hooked on plugins_loaded.
	 *
	 * @access private
	 */
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

	/**
	 * Activation hook callback.
	 *
	 * @access private
	 */
	public static function on_activation(): void {
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @access private
	 */
	public static function on_deactivation(): void {
	}

	private static function sensei_is_compatible(): bool {
		if ( ! defined( 'SENSEI_LMS_VERSION' ) ) {
			return false;
		}

		return version_compare( SENSEI_LMS_VERSION, SENSEI_MIGRATOR_MIN_SENSEI_VERSION, '>=' );
	}

	/**
	 * Hooked on admin_notices when Sensei is missing or below the required version.
	 *
	 * @access private
	 */
	public static function render_sensei_dependency_notice(): void {
		$screen        = get_current_screen();
		$valid_screens = array( 'dashboard', 'plugins', 'plugins-network' );

		if ( ! current_user_can( 'activate_plugins' ) || ! $screen || ! in_array( $screen->id, $valid_screens, true ) ) {
			return;
		}

		$message = defined( 'SENSEI_LMS_VERSION' )
			? sprintf(
				/* translators: 1: required Sensei version, 2: detected Sensei version */
				__( '<strong>Sensei Migrator</strong> requires <strong>Sensei LMS</strong> (minimum version: <strong>%1$s</strong>). Detected version: <strong>%2$s</strong>.', 'sensei-migrator' ),
				SENSEI_MIGRATOR_MIN_SENSEI_VERSION,
				SENSEI_LMS_VERSION
			)
			: __( '<strong>Sensei Migrator</strong> requires that the plugin <strong>Sensei LMS</strong> is installed and activated.', 'sensei-migrator' );

		echo '<div class="error"><p>';
		echo wp_kses( $message, array( 'strong' => array() ) );
		echo '</p></div>';
	}
}
