<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\REST\ChatController;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ChatControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn ( mixed $data ): string|false => json_encode( $data ) );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_verify_frontend_nonce_accepts_valid_nonce(): void {
		Functions\expect( 'wp_verify_nonce' )
			->once()
			->with( 'good-nonce', 'alpha_chat_frontend' )
			->andReturn( 1 );

		$this->assertTrue( ChatController::verify_frontend_nonce( 'good-nonce' ) );
	}

	public function test_verify_frontend_nonce_rejects_empty_nonce(): void {
		Functions\expect( 'wp_verify_nonce' )
			->once()
			->with( '', 'alpha_chat_frontend' )
			->andReturn( false );

		$this->assertFalse( ChatController::verify_frontend_nonce( '' ) );
	}

	public function test_sse_event_formats_wire_payload(): void {
		ob_start();
		ChatController::sse_event( 'delta', [ 'text' => 'Hi' ] );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( "event: delta\n", $output );
		$this->assertStringContainsString( 'data: {"text":"Hi"}', $output );
	}
}
