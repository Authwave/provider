<?php
namespace Authwave\Email;

use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationLogo;

class EmailBranding {
	// Match the defaults and variable references in style/variable/palette.scss.
	public const DEFAULTS = [
		"primary" => "#c328d1",
		"secondary" => "#9e77ed",
		"pageColour" => "#fafaf9",
		"panelColour" => "#ffffff",
		"borderColour" => "#e8e4e3",
		"bodyColour" => "#59514a",
		"titleColour" => "#1d1816",
		"darkPageColour" => "#453f3a",
		"darkPanelColour" => "#2b2524",
		"darkBorderColour" => "#59514a",
		"darkBodyColour" => "#d8d2d0",
		"darkTitleColour" => "#f5f5f4",
	];

	/** @param array<string, string> $defaults */
	public function __construct(private readonly array $defaults = self::DEFAULTS) {}

	/** @return array<string, string> */
	public function placeholders(ApplicationDeployment $deployment):array {
		$defaults = self::DEFAULTS;
		foreach($defaults as $key => $fallback) {
			$defaults[$key] = $this->normalise($this->defaults[$key] ?? null) ?? $fallback;
		}
		$themes = [];
		foreach($deployment->application->themes as $theme) {
			$themes[$theme->colourScheme] = $theme->colours;
		}
		$values = [];
		foreach(["light", "dark"] as $scheme) {
			$colours = $themes[$scheme] ?? [];
			$key = static fn(string $name):string => $scheme === "dark" ? "dark" . ucfirst($name) : $name;
			// Resolve in painting order so transparent overrides are composited
			// against the same underlying surfaces as on the login screen.
			$page = $this->normalise($colours["pageBackground"] ?? null, $defaults[$key("pageColour")]) ?? $defaults[$key("pageColour")];
			$panel = $this->normalise($colours["panelBackground"] ?? null, $page) ?? $defaults[$key("panelColour")];
			$body = $this->normalise($colours["bodyText"] ?? null, $panel) ?? $defaults[$key("bodyColour")];
			$title = $this->normalise($colours["headingText"] ?? null, $panel) ?? $defaults[$key("titleColour")];
			$control = $this->normalise($colours["controlBackground"] ?? null, $panel)
				?? $this->normalise($colours["panelBackground"] ?? null, $panel)
				?? $panel;
			$border = $this->normalise($colours["panelBorder"] ?? null, $panel) ?? $defaults[$key("borderColour")];
			$codeBorder = $this->normalise($colours["codeInputBorder"] ?? null, $control)
				?? $this->normalise($colours["secondary"] ?? null, $control)
				?? $defaults["secondary"];
			foreach([
				"pageColour" => $page,
				"panelColour" => $panel,
				"borderColour" => $border,
				"bodyColour" => $body,
				"titleColour" => $title,
				"mutedColour" => $body,
				"codeColour" => $this->normalise($colours["bodyText"] ?? null, $control) ?? $body,
				"codeBackgroundColour" => $control,
				"codeBorderColour" => $codeBorder,
			] as $name => $value) {
				$values[$key($name)] = $value;
			}
		}
		$logoPath = ApplicationLogo::getPath($deployment->application, preferRaster: true) ?? "/asset/default-logo.svg";
		$darkLogoPath = ApplicationLogo::getPath($deployment->application, "logo_dark", preferRaster: true);
		return $values + [
			"providerHost" => $deployment->providerHost ?? "",
			"logoPath" => $logoPath,
			"darkLogoPath" => $darkLogoPath ?? $logoPath,
			"darkLogoBackgroundColour" => $darkLogoPath ? $values["darkPanelColour"] : "#ffffff",
		];
	}

	private function normalise(mixed $colour, string $background = "#ffffff"):?string {
		if(!is_string($colour) || !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $colour)) {
			return null;
		}
		$hex = substr($colour, 1);
		if(strlen($hex) <= 4) {
			$hex = implode("", array_map(fn($digit) => $digit . $digit, str_split($hex)));
		}
		$rgb = "#" . strtolower(substr($hex, 0, 6));
		return strlen($hex) === 8 ? $this->mix($background, $rgb, hexdec(substr($hex, 6, 2)) / 255) : $rgb;
	}

	private function mix(string $a, string $b, float $amount):string {
		$channels = array_map(fn($x, $y) => (int)round($x * (1 - $amount) + $y * $amount), $this->channels($a), $this->channels($b));
		return sprintf("#%02x%02x%02x", ...$channels);
	}

	private function channels(string $colour):array {
		return array_map("hexdec", str_split(substr($colour, 1), 2));
	}
}
