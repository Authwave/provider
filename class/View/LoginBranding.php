<?php
namespace Authwave\View;

use Authwave\Model\ApplicationDeployment;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;

class LoginBranding {
	public function apply(HTMLDocument $document, Binder $binder, ApplicationDeployment $deployment):void {
		$application = $deployment->application;
		$logoDirectory = "data/upload/{$application->id}";
		$logoPath = $this->getLogoPath($logoDirectory, "logo") ?? "/asset/default-logo.svg";
		$darkLogoPath = $this->getLogoPath($logoDirectory, "logo_dark") ?? $logoPath;

		$binder->bindKeyValue("title", "$deployment->title - Login");
		$binder->bindKeyValue("applicationName", $deployment->title);

		$binder->bindKeyValue("logoPath", $logoPath);
		$binder->bindKeyValue("darkLogoPath", $darkLogoPath);

		$css = [];
		foreach($application->themes ?? [] as $theme) {
			if($rule = $theme->toCss()) {
				$css []= $rule;
			}
		}
		if($css) {
			$style = $document->createElement("style");
			$style->id = "application-theme";
			$style->textContent = implode("\n", $css);
			$document->head->appendChild($style);
		}
	}

	private function getLogoPath(string $directory, string $name):?string {
		foreach(glob("$directory/$name.*") ?: [] as $path) {
			$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			if(is_file($path) && in_array($extension, ["svg", "png", "jpg", "jpeg", "gif", "webp", "avif"], true)) {
				return "/$path";
			}
		}

		return null;
	}
}
