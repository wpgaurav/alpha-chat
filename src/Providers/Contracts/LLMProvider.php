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

	/**
	 * Stream a chat completion. $on_delta receives incremental text.
	 *
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 * @param callable(string): void                     $on_delta
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function stream( array $messages, array $options, callable $on_delta ): array;

	public function id(): string;
}
