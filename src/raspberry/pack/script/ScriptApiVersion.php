<?php

declare(strict_types=1);

namespace raspberry\pack\script;

use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function strnatcmp;
use function strtolower;
use function trim;

/**
 * Versions of the @minecraft/server Script API module ("1.16.0", "1.17.0-beta", "2.0.0").
 *
 * The server has one Script API surface (IMPLEMENTED). The "script-api-version" property says which
 * version the server presents to behavior packs: "auto" accepts whatever version each pack declares,
 * a fixed version is the highest version the server accepts (see ScriptManager).
 */
final class ScriptApiVersion{

	/** the version the shipped surface (tosapi_shim.js) was written against */
	public const IMPLEMENTED = "1.16.0";

	public const AUTO = "auto";

	public const CHECK_WARN = "warn";
	public const CHECK_STRICT = "strict";
	public const CHECK_OFF = "off";

	private const PATTERN = '/^v?(\d+)\.(\d+)(?:\.(\d+))?(?:-([0-9A-Za-z.\-]+))?$/D';

	private function __construct(){
	}

	/**
	 * Turns user input or a manifest value into a canonical "major.minor.patch[-label]" string, or
	 * AUTO for "auto". Null when it is not a version. Accepts "1.16", "v1.16.0" and [1, 16, 0].
	 */
	public static function normalize(mixed $value) : ?string{
		if(is_array($value)){
			if(count($value) !== 3){
				return null;
			}
			foreach($value as $part){
				if(!is_int($part) || $part < 0){
					return null;
				}
			}

			return implode(".", $value);
		}

		if(!is_string($value)){
			return null;
		}

		$value = trim($value);

		if(strtolower($value) === self::AUTO){
			return self::AUTO;
		}

		if(preg_match(self::PATTERN, $value, $match) !== 1){
			return null;
		}

		$label = $match[4] ?? "";

		return $match[1] . "." . $match[2] . "." . (($match[3] ?? "") !== "" ? $match[3] : "0") . ($label !== "" ? "-" . $label : "");
	}

	/**
	 * Negative when $a is older than $b, 0 when they are the same, positive when $a is newer. Both must
	 * come from normalize() and not be AUTO. A "-beta" (or any labelled) version comes before the
	 * plain release with the same numbers, like 1.17.0-beta before 1.17.0.
	 */
	public static function compare(string $a, string $b) : int{
		[$numbersA, $labelA] = self::split($a);
		[$numbersB, $labelB] = self::split($b);

		foreach([0, 1, 2] as $index){
			if($numbersA[$index] !== $numbersB[$index]){
				return $numbersA[$index] <=> $numbersB[$index];
			}
		}

		if($labelA === $labelB){
			return 0;
		}
		if($labelA === ""){
			return 1;
		}
		if($labelB === ""){
			return -1;
		}

		return strnatcmp($labelA, $labelB) <=> 0;
	}

	public static function isPrerelease(string $version) : bool{
		return self::split($version)[1] !== "";
	}

	public static function normalizeCheck(mixed $value) : string{
		$value = is_string($value) ? strtolower(trim($value)) : "";

		return in_array($value, [self::CHECK_WARN, self::CHECK_STRICT, self::CHECK_OFF], true) ? $value : self::CHECK_WARN;
	}

	/**
	 * @return array{0: array{0: int, 1: int, 2: int}, 1: string}
	 */
	private static function split(string $version) : array{
		if(preg_match(self::PATTERN, $version, $match) !== 1){
			return [[0, 0, 0], ""];
		}

		return [[(int) $match[1], (int) $match[2], (int) ($match[3] ?? "0")], $match[4] ?? ""];
	}
}
