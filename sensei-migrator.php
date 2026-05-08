<?php
/**
 * Plugin Name: Sensei Migrator
 * Description: Migrate course content, media, and enrolled students into Sensei LMS from other learning platforms. Ships with a LearnDash source adapter; designed to host additional sources.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Automattic
 * License: GPL-2.0-or-later
 * Text Domain: sensei-migrator
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator;

defined( 'ABSPATH' ) || exit;

define( 'SENSEI_MIGRATOR_VERSION', '1.0.0' );
define( 'SENSEI_MIGRATOR_FILE', __FILE__ );
define( 'SENSEI_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SENSEI_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'SENSEI_MIGRATOR_MIN_SENSEI_VERSION', '4.26.0' );

require_once SENSEI_MIGRATOR_PATH . 'includes/core/interface-source-adapter.php';
require_once SENSEI_MIGRATOR_PATH . 'includes/core/class-source-registry.php';
require_once SENSEI_MIGRATOR_PATH . 'includes/sources/learndash/class-learndash-source-adapter.php';
require_once SENSEI_MIGRATOR_PATH . 'includes/cli/class-cli-command.php';
require_once SENSEI_MIGRATOR_PATH . 'includes/class-sensei-migrator.php';

register_activation_hook( __FILE__, array( Sensei_Migrator::class, 'on_activation' ) );
register_deactivation_hook( __FILE__, array( Sensei_Migrator::class, 'on_deactivation' ) );

add_action( 'plugins_loaded', array( Sensei_Migrator::class, 'init' ), 20 );
