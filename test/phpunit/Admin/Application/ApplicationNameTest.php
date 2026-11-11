<?php

namespace Authwave\Test\Admin\Application;

use Authwave\Admin\Application\ApplicationName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationNameTest extends TestCase {
	#[DataProvider("validNames")]
	public function testConstruct_validNamesArePreservedAndTrimmed(string $input, string $expected):void {
		$sut = new ApplicationName($input);
		self::assertSame($expected, $sut->value);
	}

	public static function validNames():iterable {
		yield ["  My website  ", "My website"];
		yield ["0", "0"];
		yield ["Café 馬", "Café 馬"];
		yield [str_repeat("馬", 128), str_repeat("馬", 128)];
		yield ["<script>alert('x')</script>", "<script>alert('x')</script>"];
	}

	#[DataProvider("invalidNames")]
	public function testConstruct_invalidNamesAreRejected(mixed $name):void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new ApplicationName($name);
	}

	public static function invalidNames():iterable {
		foreach([["bad"], 123, false, new \stdClass()] as $name) {
			yield [$name];
		}
		foreach([null, "", "   ", "a\nb", "a\0b", "a\tb", "\xFF", str_repeat("x", 129)] as $name) {
			yield [$name];
		}
	}
}
