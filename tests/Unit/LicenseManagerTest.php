<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Licensing\LicenseManager;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LicenseManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		if ( ! defined( 'ALPHA_CHAT_FILE' ) ) {
			define( 'ALPHA_CHAT_FILE', '/tmp/alpha-chat/alpha-chat.php' );
		}
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant polyfill.
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		Functions\when( 'plugin_basename' )->justReturn( 'alpha-chat/alpha-chat.php' );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_clear_update_cache_survives_reentrant_deletion_hook(): void {
		$manager   = new LicenseManager();
		$deletions = 0;

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'delete_site_transient' )->alias(
			function () use ( $manager, &$deletions ): bool {
				++$deletions;
				$this->assertLessThan( 10, $deletions, 'delete_site_transient() recursed through clear_update_cache().' );
				// WordPress fires delete_site_transient_update_plugins before
				// deleting, which invokes clear_update_cache() again.
				$manager->clear_update_cache();
				return true;
			}
		);

		$manager->clear_update_cache();

		$this->assertSame( 1, $deletions );
	}
}
