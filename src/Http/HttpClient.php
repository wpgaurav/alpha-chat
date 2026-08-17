<?php
declare(strict_types=1);

namespace AlphaChat\Http;

use WP_Error;

final class HttpClient {

	public function __construct(
		private readonly int $timeout = 60,
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
	 * GET a URL that came from user input.
	 *
	 * Routes through wp_safe_remote_request, so WordPress re-validates the host
	 * on every hop — including after a redirect — instead of trusting a check we
	 * made before DNS was resolved.
	 *
	 * @param array<string, string> $headers
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HttpException
	 */
	public function get_untrusted( string $url, array $headers = [] ): array {
		return $this->request( 'GET', $url, $headers, null, true );
	}

	public function can_stream(): bool {
		return function_exists( 'curl_init' );
	}

	/**
	 * POST JSON and invoke $on_line for each SSE line (without the trailing newline).
	 *
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $body
	 * @param callable(string): void $on_line
	 *
	 * @throws HttpException
	 */
	public function post_sse( string $url, array $headers, array $body, callable $on_line ): void {
		if ( ! $this->can_stream() ) {
			throw new HttpException( 'Streaming requires the PHP cURL extension.', 0 );
		}

		$encoded = wp_json_encode( $body );
		if ( false === $encoded ) {
			throw new HttpException( 'Failed to encode request body as JSON.', 0 );
		}

		$merged = array_merge(
			[
				'Accept'       => 'text/event-stream',
				'Content-Type' => 'application/json',
				'User-Agent'   => 'AlphaChat/' . ALPHA_CHAT_VERSION . '; ' . home_url(),
			],
			$headers
		);

		$header_lines = [];
		foreach ( $merged as $name => $value ) {
			$header_lines[] = $name . ': ' . $value;
		}

		$buffer  = '';
		$error   = '';
		$timeout = max( 60, $this->timeout );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- wp_remote_request cannot consume SSE.
		$handle = curl_init( $url );
		if ( false === $handle ) {
			throw new HttpException( 'Failed to start a streaming request.', 0 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- wp_remote_request cannot consume SSE.
		curl_setopt_array(
			$handle,
			[
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_POSTFIELDS     => $encoded,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_HEADER         => false,
				CURLOPT_WRITEFUNCTION  => static function ( $unused, string $chunk ) use ( &$buffer, $on_line ): int {
					unset( $unused );
					$buffer .= $chunk;
					$pos     = strpos( $buffer, "\n" );
					while ( false !== $pos ) {
						$line   = substr( $buffer, 0, $pos );
						$buffer = substr( $buffer, $pos + 1 );
						$on_line( rtrim( $line, "\r" ) );
						$pos = strpos( $buffer, "\n" );
					}
					return strlen( $chunk );
				},
			]
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- wp_remote_request cannot consume SSE.
		$ok = curl_exec( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- paired with curl_exec above.
		$error = (string) curl_error( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- paired with curl_exec above.
		$code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		unset( $handle );

		if ( '' !== $buffer ) {
			$on_line( rtrim( $buffer, "\r" ) );
		}

		if ( false === $ok ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered as HTML.
			throw new HttpException( '' !== $error ? $error : 'Streaming request failed.', 0 );
		}

		if ( $code < 200 || $code >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is not rendered as HTML.
			throw new HttpException( sprintf( 'HTTP %d', $code ), $code );
		}
	}

	/**
	 * @param array<string, string>           $headers
	 * @param array<string, mixed>|string|null $body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HttpException
	 */
	public function request( string $method, string $url, array $headers, array|string|null $body, bool $safe = false ): array {
		$defaults = [
			'Accept'     => 'application/json',
			'User-Agent' => 'AlphaChat/' . ALPHA_CHAT_VERSION . '; ' . home_url(),
		];
		if ( null !== $body ) {
			$defaults['Content-Type'] = 'application/json';
		}

		$args = [
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 3,
			'httpversion' => '1.1',
			'headers'     => array_merge( $defaults, $headers ),
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

		$response = $safe
			? wp_safe_remote_request( $url, $args )
			: wp_remote_request( $url, $args );

		if ( $response instanceof WP_Error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered as HTML.
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
				: (string) ( $decoded['error'] ?? $decoded['message'] ?? $decoded['detail'] ?? '' );

			if ( '' === $message ) {
				$message = sprintf( 'HTTP %d', $code );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is not rendered as HTML.
			throw new HttpException( $message, (int) $code, $decoded );
		}

		return $decoded;
	}
}
