<?php
declare(strict_types=1);

namespace AlphaChat\Providers\Contracts;

interface LLMProvider {

	/**
	 * Generate a chat completion.
	 *
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function complete( array $messages, array $options = [] ): array;

	public function id(): string;
}
