<?php
declare(strict_types=1);

namespace AlphaChat\Text;

final class Similarity {

	/**
	 * Cosine similarity between two vectors of equal length.
	 *
	 * @param list<float> $a
	 * @param list<float> $b
	 */
	public static function cosine( array $a, array $b ): float {
		$length = count( $a );
		if ( 0 === $length || $length !== count( $b ) ) {
			return 0.0;
		}

		$dot   = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;

		for ( $i = 0; $i < $length; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		if ( $norm_a <= 0.0 || $norm_b <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
	}

	/**
	 * Pack a float vector into a compact binary blob (little-endian float32).
	 *
	 * @param list<float> $vector
	 */
	public static function pack( array $vector ): string {
		return pack( 'g*', ...$vector );
	}

	/**
	 * Unpack a binary blob back into a float vector.
	 *
	 * @return list<float>
	 */
	public static function unpack( string $blob ): array {
		if ( '' === $blob ) {
			return [];
		}
		$unpacked = unpack( 'g*', $blob );
		if ( false === $unpacked ) {
			return [];
		}
		return array_values( array_map( 'floatval', $unpacked ) );
	}
}
