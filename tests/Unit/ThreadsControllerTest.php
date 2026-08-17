<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\REST\ThreadsController;
use PHPUnit\Framework\TestCase;

final class ThreadsControllerTest extends TestCase {

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	public static function formula_provider(): array {
		return [
			[ '=cmd|/c calc', "'=cmd|/c calc" ],
			[ '+1234', "'+1234" ],
			[ '-1+1', "'-1+1" ],
			[ '@SUM(A1:A9)', "'@SUM(A1:A9)" ],
			[ "\tleading tab", "'\tleading tab" ],
		];
	}

	/**
	 * @dataProvider formula_provider
	 */
	public function test_defuses_spreadsheet_formulas( string $input, string $expected ): void {
		$this->assertSame( $expected, ThreadsController::defuse( $input ) );
	}

	public function test_leaves_ordinary_content_untouched(): void {
		$this->assertSame( 'How do refunds work?', ThreadsController::defuse( 'How do refunds work?' ) );
		$this->assertSame( '', ThreadsController::defuse( '' ) );
		$this->assertSame( '2 + 2 = 4', ThreadsController::defuse( '2 + 2 = 4' ) );
	}
}
