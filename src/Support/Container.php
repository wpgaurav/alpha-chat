<?php
declare(strict_types=1);

namespace AlphaChat\Support;

use Closure;
use RuntimeException;

final class Container {

	/** @var array<class-string, Closure> */
	private array $factories = [];

	/** @var array<class-string, object> */
	private array $instances = [];

	/**
	 * Register a factory for a service.
	 *
	 * @template T of object
	 *
	 * @param class-string<T>      $id
	 * @param Closure(self): T     $factory
	 */
	public function set( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $id
	 *
	 * @return T
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			/** @var T */
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException( sprintf( 'Service %s not registered.', $id ) );
		}

		/** @var T $instance */
		$instance = ( $this->factories[ $id ] )( $this );
		$this->instances[ $id ] = $instance;

		return $instance;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}
}
