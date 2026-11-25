<?php
namespace Authwave\Test\Model;

use Authwave\Model\ApplicationSessionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationSessionPolicyTest extends TestCase {
	#[DataProvider("invalidTimeouts")]
	public function testConstruct_rejectsInvalidTimeouts(int $maximum, int $idle):void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new ApplicationSessionPolicy("app", $maximum, $idle);
	}

	public static function invalidTimeouts():iterable {
		yield [0, 1];
		yield [60, 0];
		yield [65536, 1];
		yield [15, 30];
	}
}
