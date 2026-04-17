<?php
declare(strict_types=1);

namespace AlphaChat\Providers\Contracts;

interface VectorStore {

	/**
	 * Upsert a vector keyed by id.
	 *
	 * @param list<float>          $vector
	 * @param array<string, mixed> $metadata
	 */
	public function upsert( string $id, array $vector, array $metadata = [] ): void;

	public function delete( string $id ): void;

	/**
	 * Search for nearest neighbours.
	 *
	 * @param list<float> $query
	 *
	 * @return list<array{id: string, score: float, metadata: array<string, mixed>}>
	 */
	public function search( array $query, int $limit = 5, float $threshold = 0.0 ): array;
}
