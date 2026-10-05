<?php
namespace Authwave\Model;

class ApplicationLogo {
	public static function getPath(Application $application, string $name = "logo", bool $preferRaster = false):?string {
		$paths = glob("data/upload/{$application->id}/$name.*") ?: [];
		$extensions = ["png", "jpg", "jpeg", "gif", "webp", "avif", "svg"];
		if($preferRaster) {
			usort($paths, fn(string $a, string $b) =>
				array_search(strtolower(pathinfo($a, PATHINFO_EXTENSION)), $extensions)
				<=> array_search(strtolower(pathinfo($b, PATHINFO_EXTENSION)), $extensions));
		}
		foreach($paths as $path) {
			if(is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
				return "/" . implode("/", array_map("rawurlencode", explode("/", $path)));
			}
		}
		return null;
	}
}
