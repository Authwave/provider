<?php
namespace Authwave\UI;

/** Local, repeatable artwork. The email itself is never included in the SVG. */
class EmailAvatar {
	private const SILHOUETTES = [
		"square" => "inset(15%)",
		"circle" => "circle(40% at 50% 50%)",
		"triangle" => "polygon(50% 10%, 90% 80%, 10% 80%)",
		"rhomboid" => "polygon(25% 17.5%, 87.5% 17.5%, 75% 82.5%, 12.5% 82.5%)",
		"ellipse" => "ellipse(45% 30% at 50% 50%)",
		"diamond" => "polygon(50% 5%, 95% 50%, 50% 95%, 5% 50%)",
		"hexagon" => "polygon(30% 12.5%, 70% 12.5%, 90% 50%, 70% 87.5%, 30% 87.5%, 10% 50%)",
	];
	private const SLICES = ["pizza-centre", "pizza-offset", "parallel-0", "parallel-45", "parallel-90", "quadrants"];
	private const SLICE_TARGETS = ["none", "background", "shapes", "both"];

	public static function svg(string $email):string {
		$seed = array_values(unpack("C*", hash("sha256", strtolower(trim($email)), true)));
		$shape = array_keys(self::SILHOUETTES)[$seed[0] % count(self::SILHOUETTES)];
		$extraCount = $seed[1] % 3;
		$background = $seed[2] % 8;
		$slicing = self::SLICES[$seed[3] % count(self::SLICES)];
		$target = self::SLICE_TARGETS[$seed[15] % count(self::SLICE_TARGETS)];
		$count = $slicing === "quadrants" ? 4 : 2 + $seed[4] % 3;
		// A light/dark slice guarantees contrast; accent hues are at least 120° apart.
		$accent = $seed[5] % 6;
		if(2 + $accent === $background) $accent = ($accent + 1) % 6;
		$opposite = ($accent + 3) % 6;
		if(2 + $opposite === $background) $opposite = ($accent + 2) % 6;
		$colours = [$background < 2 ? 1 - $background : $seed[6] % 2, 2 + $accent, 2 + $opposite];
		// Keep all slices different from the background so the silhouette remains visible.
		$colours[] = $background < 2 ? 2 + ($accent + 4) % 6 : 1 - $colours[0];
		if($seed[8] % 2) $colours = array_reverse($colours);
		$paths = match($slicing) {
			"pizza-centre" => self::pizza(20, 20, $count, ($seed[9] % 8) * 45),
			"pizza-offset" => self::pizza($seed[10] % 2 ? 12 : 28, $seed[11] % 2 ? 12 : 28, $count, ($seed[9] % 8) * 45),
			"quadrants" => self::quadrants(),
			default => self::parallel((int)substr($slicing, 9), $count),
		};
		$sliceBackground = $target === "background" || $target === "both";
		$sliceShapes = $target === "shapes" || $target === "both";
		// Separate palette entries keep foreground shapes visible over a sliced background.
		$backgroundColours = array_values(array_diff(range(0, 7), $colours));
		$shift = $seed[16] % count($backgroundColours);
		$backgroundColours = array_merge(array_slice($backgroundColours, $shift), array_slice($backgroundColours, 0, $shift));
		$artwork = '<g class="avatar-background">' . self::paint($sliceBackground ? $paths : [], $backgroundColours, $background) . '</g>';
		$artwork .= '<g class="avatar-silhouette" style="clip-path:' . self::SILHOUETTES[$shape] . ' view-box">';
		$artwork .= self::paint($sliceShapes ? $paths : [], $colours, $colours[0]);
		$artwork .= '</g>';
		// Optional symbols sit on a fixed corner grid rather than drifting/overlapping randomly.
		$corners = [[9, 9], [31, 9], [31, 31], [9, 31]];
		for($i = 0; $i < $extraCount; $i++) {
			[$x, $y] = $corners[($seed[12] + $i * 2) % 4];
			$symbol = array_values(self::SILHOUETTES)[$seed[13 + $i] % count(self::SILHOUETTES)];
			$artwork .= '<g class="avatar-extra" transform="translate(' . ($x - 5) . ' ' . ($y - 5) . ') scale(.25)" style="clip-path:' . $symbol . ' view-box">';
			$artwork .= self::paint($sliceShapes ? $paths : [], $colours, $colours[$i + 1]) . '</g>';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" class="email-avatar" viewBox="0 0 40 40" aria-hidden="true" focusable="false" data-shape="' . $shape . '" data-slicing="' . $slicing . '" data-slice-target="' . $target . '">' . $artwork . '</svg>';
	}

	private static function paint(array $paths, array $colours, int $solid):string {
		if(!$paths) return '<rect class="avatar-colour-' . $solid . '" width="40" height="40" />';
		$artwork = '';
		foreach($paths as $i => $path) {
			$artwork .= '<path class="avatar-slice avatar-colour-' . $colours[$i] . '" d="' . $path . '" />';
		}
		return $artwork;
	}

	/** Large radial wedges cover the silhouette, including an off-centre origin. */
	private static function pizza(int $x, int $y, int $count, int $rotation):array {
		$paths = [];
		for($i = 0; $i < $count; $i++) {
			$start = deg2rad($rotation + $i * 360 / $count);
			$end = deg2rad($rotation + ($i + 1) * 360 / $count);
			$x1 = self::number($x + 64 * cos($start));
			$y1 = self::number($y + 64 * sin($start));
			$x2 = self::number($x + 64 * cos($end));
			$y2 = self::number($y + 64 * sin($end));
			$paths[] = "M $x $y L $x1 $y1 A 64 64 0 0 1 $x2 $y2 Z";
		}
		return $paths;
	}

	/** Rotate stripe corners about the canvas centre; diagonal bands need extra width. */
	private static function parallel(int $angle, int $count):array {
		$paths = [];
		$extent = $angle === 45 ? 30 : 20;
		for($i = 0; $i < $count; $i++) {
			$left = -$extent + $i * 2 * $extent / $count;
			$right = -$extent + ($i + 1) * 2 * $extent / $count;
			$points = [];
			foreach([[$left, -30], [$right, -30], [$right, 30], [$left, 30]] as [$x, $y]) {
				$radians = deg2rad($angle);
				$points[] = self::number(20 + $x * cos($radians) - $y * sin($radians)) . ' ' . self::number(20 + $x * sin($radians) + $y * cos($radians));
			}
			$paths[] = 'M ' . implode(' L ', $points) . ' Z';
		}
		return $paths;
	}

	private static function quadrants():array {
		return ["M 0 0 H 20 V 20 H 0 Z", "M 20 0 H 40 V 20 H 20 Z", "M 20 20 H 40 V 40 H 20 Z", "M 0 20 H 20 V 40 H 0 Z"];
	}

	private static function number(float $value):string {
		return number_format($value, 3, '.', '');
	}
}
