<?php
/**
 * `wp sensei migrate ...` commands.
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator\CLI;

use Sensei_Migrator\Core\Source_Adapter;
use Sensei_Migrator\Core\Source_Registry;
use Sensei_Migrator\Sensei_Migrator;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Migrate course content from another LMS into Sensei.
 */
class CLI_Command {

	/**
	 * Register the WP-CLI command. Called once from the main plugin bootstrap.
	 *
	 * @access private
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'sensei migrate', self::class );
	}

	/**
	 * Run a migration job.
	 *
	 * ## OPTIONS
	 *
	 * --from=<source>
	 * : Source LMS slug. See `wp sensei migrate sources` for available adapters.
	 *
	 * [--dry-run]
	 * : Inspect source content and print a summary without writing to Sensei.
	 *
	 * [--include-enrollments]
	 * : Include enrolled students.
	 *
	 * [--include-media]
	 * : Sideload images and other media referenced from source content.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sensei migrate run --from=learndash --dry-run
	 *     wp sensei migrate run --from=learndash --include-media --include-enrollments
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 *
	 * @access private
	 */
	public function run( array $args, array $assoc_args ): void {
		$adapter = $this->resolve_adapter( $assoc_args );

		if ( ! empty( $assoc_args['include-enrollments'] ) ) {
			WP_CLI::warning( '--include-enrollments is not yet implemented; flag ignored.' );
		}
		if ( ! empty( $assoc_args['include-media'] ) ) {
			WP_CLI::warning( '--include-media is not yet implemented; flag ignored.' );
		}

		try {
			$inventory = $adapter->inventory();
			WP_CLI::log( sprintf( 'Source: %s', $adapter->label() ) );
			WP_CLI::log( wp_json_encode( $inventory, JSON_PRETTY_PRINT ) );

			if ( ! empty( $assoc_args['dry-run'] ) ) {
				$preview = array(
					'first_course'   => $this->first( $adapter->read_courses() ),
					'first_lesson'   => $this->first( $adapter->read_lessons() ),
					'first_quiz'     => $this->first( $adapter->read_quizzes() ),
					'first_question' => $this->first( $adapter->read_questions() ),
				);
				WP_CLI::log( '' );
				WP_CLI::log( 'Dry-run preview (first record per type):' );
				WP_CLI::log( wp_json_encode( $preview, JSON_PRETTY_PRINT ) );
				WP_CLI::success( 'Dry-run complete. No data written.' );
				return;
			}

			WP_CLI::warning( 'Live migration is not yet implemented. Re-run with --dry-run for now.' );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * List the migration sources registered on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sensei migrate sources
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags (unused).
	 *
	 * @access private
	 */
	public function sources( array $args, array $assoc_args ): void {
		$registry = $this->registry();
		$adapters = $registry->all();

		if ( ! $adapters ) {
			WP_CLI::log( 'No source adapters registered.' );
			return;
		}

		foreach ( $adapters as $adapter ) {
			WP_CLI::log(
				sprintf(
					'%-16s %s%s',
					$adapter->slug(),
					$adapter->label(),
					$adapter->detect() ? ' (detected on this site)' : ''
				)
			);
		}
	}

	private function resolve_adapter( array $assoc_args ): Source_Adapter {
		$source_slug = (string) ( $assoc_args['from'] ?? '' );
		if ( '' === $source_slug ) {
			WP_CLI::error( 'Missing --from=<source>. Run `wp sensei migrate sources` to list adapters.' );
		}

		$adapter = $this->registry()->get( $source_slug );
		if ( ! $adapter ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Run `wp sensei migrate sources` to list adapters.', $source_slug ) );
		}

		if ( ! $adapter->detect() ) {
			WP_CLI::error( sprintf( 'Source "%s" is not present on this site.', $adapter->label() ) );
		}

		return $adapter;
	}

	private function registry(): Source_Registry {
		$registry = Sensei_Migrator::registry();
		if ( ! $registry ) {
			WP_CLI::error( 'Sensei Migrator is not initialized. Is Sensei LMS active?' );
		}
		return $registry;
	}

	private function first( iterable $records ): ?array {
		foreach ( $records as $record ) {
			return $record;
		}
		return null;
	}
}
