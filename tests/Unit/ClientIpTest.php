<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Support\ClientIp;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		$_SERVER = [];
	}

	protected function tearDown(): void {
		$_SERVER = [];
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/** @param list<string> $proxies */
	private function trust( array $proxies, bool $cloudflare = false ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, mixed $value ) use ( $proxies, $cloudflare ): mixed {
				if ( 'alpha_chat_trust_cloudflare' === $hook ) {
					return $cloudflare;
				}
				if ( 'alpha_chat_trusted_proxies' === $hook ) {
					// Mirror production: the filter receives the merged list.
					return array_merge( $proxies, is_array( $value ) ? $value : [] );
				}
				return $value;
			}
		);
	}

	public function test_ignores_forwarded_header_from_untrusted_peer(): void {
		$this->trust( [] );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

		$this->assertSame( '203.0.113.9', ClientIp::get() );
	}

	public function test_spoofed_header_cannot_shift_the_bucket(): void {
		$this->trust( [] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$seen = [];
		foreach ( [ '1.1.1.1', '2.2.2.2', '3.3.3.3' ] as $forged ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $forged;
			$seen[]                          = ClientIp::get();
		}

		$this->assertSame( [ '203.0.113.9', '203.0.113.9', '203.0.113.9' ], $seen );
	}

	public function test_honours_forwarded_header_from_trusted_proxy(): void {
		$this->trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

		$this->assertSame( '198.51.100.1', ClientIp::get() );
	}

	public function test_takes_rightmost_untrusted_hop(): void {
		$this->trust( [ '10.0.0.0/8' ] );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		// The caller prepended a forged hop; the real client is the last one that
		// is not itself a trusted proxy.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.1, 10.0.0.9';

		$this->assertSame( '198.51.100.1', ClientIp::get() );
	}

	public function test_cidr_range_matching(): void {
		$this->trust( [ '192.0.2.0/24' ] );
		$_SERVER['REMOTE_ADDR']    = '192.0.2.77';
		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.4';

		$this->assertSame( '198.51.100.4', ClientIp::get() );
	}

	public function test_cidr_range_excludes_outside_addresses(): void {
		$this->trust( [ '192.0.2.0/24' ] );
		$_SERVER['REMOTE_ADDR']    = '192.0.3.77';
		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.4';

		$this->assertSame( '192.0.3.77', ClientIp::get() );
	}

	public function test_strips_port_and_rejects_garbage(): void {
		$this->trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 198.51.100.1:9999';

		$this->assertSame( '198.51.100.1', ClientIp::get() );
	}

	public function test_cloudflare_edge_is_trusted_by_default(): void {
		$this->trust( [], true );
		// 172.64.0.0/13 is a published Cloudflare edge range.
		$_SERVER['REMOTE_ADDR']            = '172.68.10.4';
		$_SERVER['HTTP_CF_CONNECTING_IP']  = '198.51.100.23';

		$this->assertSame( '198.51.100.23', ClientIp::get() );
	}

	public function test_cloudflare_ipv6_edge_is_trusted(): void {
		$this->trust( [], true );
		$_SERVER['REMOTE_ADDR']           = '2606:4700:1::a';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.24';

		$this->assertSame( '198.51.100.24', ClientIp::get() );
	}

	public function test_non_cloudflare_peer_cannot_forge_the_cloudflare_header(): void {
		$this->trust( [], true );
		// The header is the one Cloudflare sets, but the peer is not Cloudflare.
		$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';

		$this->assertSame( '203.0.113.9', ClientIp::get() );
	}

	public function test_cloudflare_trust_can_be_disabled(): void {
		$this->trust( [], false );
		$_SERVER['REMOTE_ADDR']           = '172.68.10.4';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.26';

		$this->assertSame( '172.68.10.4', ClientIp::get() );
	}

	public function test_falls_back_when_header_has_nothing_usable(): void {
		$this->trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'garbage';

		$this->assertSame( '10.0.0.5', ClientIp::get() );
	}
}
