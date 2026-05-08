<?php
/**
 * Registry of available source adapters.
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator\Core;

defined( 'ABSPATH' ) || exit;

class Source_Registry {

	/**
	 * @var array<string,Source_Adapter>
	 */
	private array $adapters = array();

	public function register( Source_Adapter $adapter ): void {
		$this->adapters[ $adapter->slug() ] = $adapter;
	}

	public function get( string $slug ): ?Source_Adapter {
		return $this->adapters[ $slug ] ?? null;
	}

	/**
	 * @return array<string,Source_Adapter>
	 */
	public function all(): array {
		return $this->adapters;
	}

	/**
	 * Adapters whose detect() returns true on this site.
	 *
	 * @return array<string,Source_Adapter>
	 */
	public function detected(): array {
		return array_filter(
			$this->adapters,
			static fn( Source_Adapter $adapter ): bool => $adapter->detect()
		);
	}
}
