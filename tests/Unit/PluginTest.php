<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	public function test_instance_returns_singleton(): void {
		$a = Plugin::instance();
		$b = Plugin::instance();

		$this->assertSame( $a, $b );
	}
}
