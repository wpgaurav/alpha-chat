<?php
declare(strict_types=1);

namespace AlphaChat\Http;

use WP_Error;

final class HttpClient {

	public function __construct(
		private readonly int $timeout = 30,
	) {}

	/**
	 * @param array<string, string>      $headers
	 * @param array<string, mixed>|string $body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HttpException
	 */
	public function post_json( string $url, array $headers, array|string $body ): array {
		return $this->request( 'POST', $url, $headers, $body );
	}

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HttpException
	 */
	public function get_json( string $url, array $headers = [] ): array {
		return $this->request( 'GET', $url, $headers, null );
	}

	/**
	 * @param array<string, string>           $headers
	 * @param array<string, mixed>|string|null $body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HttpException
	 */
	public function request( string $method, string $url, array $headers, array|string|null $body ): array {
		$args = [
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 3,
			'httpversion' => '1.1',
			'headers'     => array_merge(
				[
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'AlphaChat/' . ALPHA_CHAT_VERSION . '; ' . home_url(),
				],
				$headers
			),
		];

		if ( null !== $body ) {
			if ( is_string( $body ) ) {
				$args['body'] = $body;
			} else {
				$encoded = wp_json_encode( $body );
				if ( false === $encoded ) {
					throw new HttpException( 'Failed to encode request body as JSON.', 0 );
				}
				$args['body'] = $encoded;
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error ) {
			throw new HttpException( $response->get_error_message(), 0 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		$decoded = ( '' === $raw ) ? [] : json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = [ 'raw' => $raw ];
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded['error'] ?? null )
				? ( $decoded['error']['message'] ?? '' )
				: (string) ( $decoded['error'] ?? $decoded['message'] ?? '' );

			if ( '' === $message ) {
				$message = sprintf( 'HTTP %d', $code );
			}

			throw new HttpException( $message, (int) $code, $decoded );
		}

		return $decoded;
	}
}
