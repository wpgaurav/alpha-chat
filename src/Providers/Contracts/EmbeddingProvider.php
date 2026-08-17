<?php
declare(strict_types=1);

namespace AlphaChat\Providers\Contracts;

interface EmbeddingProvider {

	/**
	 * Generate embeddings for a batch of inputs.
	 *
	 * @param list<string>         $inputs
	 * @param array<string, mixed> $options Optional `input_type` (`query` or `document`).
	 *
	 * @return list<list<float>>
	 */
	public function embed( array $inputs, array $options = [] ): array;

	public function model(): string;

	public function dimensions(): int;
}
