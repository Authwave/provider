<?php
namespace Authwave\Model;

use InvalidArgumentException;

class ApplicationTheme {
	public const COLOUR_PROPERTIES = [
		"primary" => "--pal--theme",
		"secondary" => "--pal--theme-secondary",
		"codeInputBorder" => "--pal--code-input--border",
		"positive" => "--pal--positive",
		"negative" => "--pal--negative",
		"warning" => "--pal--warning",
		"interactionShade" => "--pal--interaction--shade",
		"focusOutline" => "--pal--focus--outline",
		"pageBackground" => "--pal--page--background",
		"panelBackground" => "--pal--panel--background",
		"panelBorder" => "--pal--panel--border",
		"controlBorder" => "--pal--control--border",
		"controlBackground" => "--pal--control--background",
		"controlBorderHover" => "--pal--control--border-hover",
		"controlBackgroundActive" => "--pal--control--background-active",
		"controlHighlight" => "--pal--control--highlight",
		"controlShade" => "--pal--control--shade",
		"controlHighlightHover" => "--pal--control--highlight-hover",
		"controlShadeHover" => "--pal--control--shade-hover",
		"buttonBackground" => "--pal--button--background",
		"buttonText" => "--pal--button--text",
		"buttonPrimaryText" => "--pal--button--text-primary",
		"buttonPrimaryBackground" => "--pal--button--background-primary",
		"buttonBorder" => "--pal--button--border",
		"buttonPrimaryBorder" => "--pal--button--border-primary",
		"buttonBorderHover" => "--pal--button--border-hover",
		"buttonBackgroundActive" => "--pal--button--background-active",
		"buttonPrimaryBorderHover" => "--pal--button--border-primary-hover",
		"buttonPrimaryBackgroundActive" => "--pal--button--background-primary-active",
		"bodyText" => "--pal--body--text",
		"controlPlaceholder" => "--pal--control--placeholder",
		"headingText" => "--pal--heading--text",
		"linkText" => "--pal--link--text",
		"linkTextHover" => "--pal--link--text-hover",
		"linkTextActive" => "--pal--link--text-active",
		"linkBackgroundActive" => "--pal--link--background-active",
	];

	/** @param array<string, mixed> $colours */
	public function __construct(
		public readonly string $colourScheme,
		public readonly array $colours,
	) {
		if(!in_array($colourScheme, ["light", "dark"], true)) {
			throw new InvalidArgumentException("Unknown colour scheme: $colourScheme");
		}
	}

	public static function fromJson(string $colourScheme, string $json):self {
		$colours = json_decode($json, true);
		return new self($colourScheme, is_array($colours) ? $colours : []);
	}

	public function toCss():string {
		$declarations = [];
		foreach(self::COLOUR_PROPERTIES as $key => $property) {
			$colour = $this->colours[$key] ?? null;
			// Whitelist both keys and values before embedding database content in CSS.
			if(is_string($colour) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $colour)) {
				$declarations []= "$property: $colour;";
			}
		}
		if(!$declarations) {
			return "";
		}

		return ":root[data-color-scheme=\"$this->colourScheme\"] { "
			. implode(" ", $declarations) . " }";
	}
}
