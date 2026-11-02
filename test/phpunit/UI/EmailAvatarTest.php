<?php
namespace Authwave\Test\UI;

use Authwave\UI\EmailAvatar;
use DOMDocument;
use PHPUnit\Framework\TestCase;

class EmailAvatarTest extends TestCase {
	public function testAvatarsAreStableAndDifferentForDifferentEmails():void {
		$avatar = EmailAvatar::svg("sienna@example.test");
		self::assertSame($avatar, EmailAvatar::svg(" SIENNA@example.test "));
		self::assertNotSame($avatar, EmailAvatar::svg("ammar@example.test"));
		self::assertStringNotContainsString("sienna", $avatar);
		self::assertStringNotContainsString("example.test", $avatar);
	}

	public function testUntrustedEmailCanOnlyAffectGeneratedGeometry():void {
		$avatar = EmailAvatar::svg('"><script>alert(1)</script>@example.test');
		$document = new DOMDocument();
		self::assertTrue($document->loadXML($avatar));
		self::assertSame("svg", $document->documentElement->tagName);
		self::assertSame("true", $document->documentElement->getAttribute("aria-hidden"));
		self::assertGreaterThanOrEqual(1, $document->getElementsByTagName("g")->length);
		self::assertCount(0, $document->getElementsByTagName("script"));
		self::assertCount(0, $document->getElementsByTagName("image"));
		self::assertStringNotContainsString("#c328d1", $avatar, "Colours are supplied by CSS, not embedded in the artwork.");
	}
	public function testHashSelectsEverySilhouetteSlicingModeAndOptionalShapeCount():void {
		$shapes = $slices = $counts = $targets = [];
		$signatures = [];
		for($i = 0; $i < 256; $i++) {
			$document = new DOMDocument();
			self::assertTrue($document->loadXML(EmailAvatar::svg("user-$i@example.test")));
			$shape = $document->documentElement->getAttribute("data-shape");
			$slicing = $document->documentElement->getAttribute("data-slicing");
			$extras = 0;
			foreach($document->getElementsByTagName("g") as $group) {
				if($group->getAttribute("class") === "avatar-extra") $extras++;
			}
			$shapes[$shape] = $slices[$slicing] = $counts[$extras] = true;
			$signatures[$shape . ":" . $slicing . ":" . $extras] = true;
			$target = $document->documentElement->getAttribute("data-slice-target");
			$targets[$target] = true;
			foreach($document->getElementsByTagName("g") as $group) {
				$isBackground = $group->getAttribute("class") === "avatar-background";
				$shouldSlice = $target === "both" || $target === ($isBackground ? "background" : "shapes");
				self::assertSame($shouldSlice, $group->getElementsByTagName("path")->length > 0, "Each layer follows the hash-selected slicing target.");
			}
			self::assertStringNotContainsString('id="', $document->saveXML(), "Repeated avatars need no shared clipping IDs.");
		}
		self::assertCount(7, $shapes);
		self::assertCount(6, $slices);
		self::assertCount(3, $counts);
		self::assertCount(4, $targets);
		self::assertGreaterThan(80, count($signatures), "Artwork varies structurally, not just in its colour or position.");
	}

}
