<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Text\Similarity;
use PHPUnit\Framework\TestCase;

final class SimilarityTest extends TestCase {

	public function test_identical_vectors_have_cosine_one(): void {
		$vector = [ 1.0, 0.5, -0.25, 0.75 ];
		$this->assertEqualsWithDelta( 1.0, Similarity::cosine( $vector, $vector ), 0.0001 );
	}

	public function test_orthogonal_vectors_have_cosine_zero(): void {
		$this->assertEqualsWithDelta( 0.0, Similarity::cosine( [ 1.0, 0.0 ], [ 0.0, 1.0 ] ), 0.0001 );
	}

	public function test_opposite_vectors_have_cosine_minus_one(): void {
		$this->assertEqualsWithDelta( -1.0, Similarity::cosine( [ 1.0, 2.0 ], [ -1.0, -2.0 ] ), 0.0001 );
	}

	public function test_mismatched_length_returns_zero(): void {
		$this->assertSame( 0.0, Similarity::cosine( [ 1.0, 2.0 ], [ 1.0 ] ) );
	}

	public function test_zero_vector_returns_zero(): void {
		$this->assertSame( 0.0, Similarity::cosine( [ 0.0, 0.0 ], [ 1.0, 1.0 ] ) );
	}

	public function test_pack_and_unpack_roundtrip(): void {
		$original = [ 0.1, -0.5, 1.25, 0.0, -3.14 ];
		$packed   = Similarity::pack( $original );
		$restored = Similarity::unpack( $packed );

		$this->assertCount( count( $original ), $restored );
		foreach ( $original as $i => $value ) {
			$this->assertEqualsWithDelta( $value, $restored[ $i ], 0.0001 );
		}
	}

	public function test_unpack_empty_string(): void {
		$this->assertSame( [], Similarity::unpack( '' ) );
	}
}
