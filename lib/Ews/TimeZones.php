<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

/**
 * Exchange only understands Windows time zone ids. This maps the IANA zone
 * reported by the browser (e.g. "Europe/Paris") to its Windows id
 * (e.g. "Romance Standard Time"), based on the Unicode CLDR mapping.
 */
final class TimeZones {
	private const MAP = [
		// Europe
		'Europe/Paris' => 'Romance Standard Time',
		'Europe/Brussels' => 'Romance Standard Time',
		'Europe/Madrid' => 'Romance Standard Time',
		'Europe/Copenhagen' => 'Romance Standard Time',
		'Europe/Monaco' => 'W. Europe Standard Time',
		'Europe/Luxembourg' => 'W. Europe Standard Time',
		'Europe/Berlin' => 'W. Europe Standard Time',
		'Europe/Zurich' => 'W. Europe Standard Time',
		'Europe/Amsterdam' => 'W. Europe Standard Time',
		'Europe/Rome' => 'W. Europe Standard Time',
		'Europe/Vienna' => 'W. Europe Standard Time',
		'Europe/Stockholm' => 'W. Europe Standard Time',
		'Europe/Oslo' => 'W. Europe Standard Time',
		'Europe/Andorra' => 'W. Europe Standard Time',
		'Europe/Malta' => 'W. Europe Standard Time',
		'Europe/Gibraltar' => 'W. Europe Standard Time',
		'Europe/Vaduz' => 'W. Europe Standard Time',
		'Europe/San_Marino' => 'W. Europe Standard Time',
		'Europe/Vatican' => 'W. Europe Standard Time',
		'Europe/Prague' => 'Central Europe Standard Time',
		'Europe/Budapest' => 'Central Europe Standard Time',
		'Europe/Bratislava' => 'Central Europe Standard Time',
		'Europe/Ljubljana' => 'Central Europe Standard Time',
		'Europe/Belgrade' => 'Central Europe Standard Time',
		'Europe/Warsaw' => 'Central European Standard Time',
		'Europe/Zagreb' => 'Central European Standard Time',
		'Europe/Sarajevo' => 'Central European Standard Time',
		'Europe/Skopje' => 'Central European Standard Time',
		'Europe/London' => 'GMT Standard Time',
		'Europe/Dublin' => 'GMT Standard Time',
		'Europe/Lisbon' => 'GMT Standard Time',
		'Atlantic/Canary' => 'GMT Standard Time',
		'Atlantic/Madeira' => 'GMT Standard Time',
		'Europe/Athens' => 'GTB Standard Time',
		'Europe/Bucharest' => 'GTB Standard Time',
		'Asia/Nicosia' => 'GTB Standard Time',
		'Europe/Helsinki' => 'FLE Standard Time',
		'Europe/Kiev' => 'FLE Standard Time',
		'Europe/Kyiv' => 'FLE Standard Time',
		'Europe/Riga' => 'FLE Standard Time',
		'Europe/Sofia' => 'FLE Standard Time',
		'Europe/Tallinn' => 'FLE Standard Time',
		'Europe/Vilnius' => 'FLE Standard Time',
		'Europe/Istanbul' => 'Turkey Standard Time',
		'Europe/Moscow' => 'Russian Standard Time',
		'Atlantic/Reykjavik' => 'Greenwich Standard Time',
		// Africa
		'Africa/Casablanca' => 'Morocco Standard Time',
		'Africa/Algiers' => 'W. Central Africa Standard Time',
		'Africa/Tunis' => 'W. Central Africa Standard Time',
		'Africa/Lagos' => 'W. Central Africa Standard Time',
		'Africa/Douala' => 'W. Central Africa Standard Time',
		'Africa/Libreville' => 'W. Central Africa Standard Time',
		'Africa/Kinshasa' => 'W. Central Africa Standard Time',
		'Africa/Abidjan' => 'Greenwich Standard Time',
		'Africa/Dakar' => 'Greenwich Standard Time',
		'Africa/Bamako' => 'Greenwich Standard Time',
		'Africa/Ouagadougou' => 'Greenwich Standard Time',
		'Africa/Conakry' => 'Greenwich Standard Time',
		'Africa/Lome' => 'Greenwich Standard Time',
		'Africa/Cairo' => 'Egypt Standard Time',
		'Africa/Johannesburg' => 'South Africa Standard Time',
		'Africa/Nairobi' => 'E. Africa Standard Time',
		'Indian/Mayotte' => 'E. Africa Standard Time',
		'Indian/Comoro' => 'E. Africa Standard Time',
		'Indian/Antananarivo' => 'E. Africa Standard Time',
		'Indian/Reunion' => 'Mauritius Standard Time',
		'Indian/Mauritius' => 'Mauritius Standard Time',
		// Americas (incl. French overseas territories)
		'America/Martinique' => 'SA Western Standard Time',
		'America/Guadeloupe' => 'SA Western Standard Time',
		'America/St_Barthelemy' => 'SA Western Standard Time',
		'America/Marigot' => 'SA Western Standard Time',
		'America/Cayenne' => 'SA Eastern Standard Time',
		'America/Miquelon' => 'Saint Pierre Standard Time',
		'America/Montreal' => 'Eastern Standard Time',
		'America/Toronto' => 'Eastern Standard Time',
		'America/New_York' => 'Eastern Standard Time',
		'America/Chicago' => 'Central Standard Time',
		'America/Denver' => 'Mountain Standard Time',
		'America/Phoenix' => 'US Mountain Standard Time',
		'America/Los_Angeles' => 'Pacific Standard Time',
		'America/Vancouver' => 'Pacific Standard Time',
		'America/Anchorage' => 'Alaskan Standard Time',
		'Pacific/Honolulu' => 'Hawaiian Standard Time',
		'America/Mexico_City' => 'Central Standard Time (Mexico)',
		'America/Sao_Paulo' => 'E. South America Standard Time',
		'America/Argentina/Buenos_Aires' => 'Argentina Standard Time',
		'America/Bogota' => 'SA Pacific Standard Time',
		'America/Lima' => 'SA Pacific Standard Time',
		'America/Santiago' => 'Pacific SA Standard Time',
		// Asia / Pacific
		'Asia/Dubai' => 'Arabian Standard Time',
		'Asia/Riyadh' => 'Arab Standard Time',
		'Asia/Jerusalem' => 'Israel Standard Time',
		'Asia/Beirut' => 'Middle East Standard Time',
		'Asia/Kolkata' => 'India Standard Time',
		'Asia/Calcutta' => 'India Standard Time',
		'Asia/Bangkok' => 'SE Asia Standard Time',
		'Asia/Ho_Chi_Minh' => 'SE Asia Standard Time',
		'Asia/Singapore' => 'Singapore Standard Time',
		'Asia/Shanghai' => 'China Standard Time',
		'Asia/Hong_Kong' => 'China Standard Time',
		'Asia/Tokyo' => 'Tokyo Standard Time',
		'Asia/Seoul' => 'Korea Standard Time',
		'Australia/Sydney' => 'AUS Eastern Standard Time',
		'Australia/Melbourne' => 'AUS Eastern Standard Time',
		'Australia/Brisbane' => 'E. Australia Standard Time',
		'Australia/Perth' => 'W. Australia Standard Time',
		'Pacific/Auckland' => 'New Zealand Standard Time',
		'Pacific/Noumea' => 'Central Pacific Standard Time',
		'Pacific/Tahiti' => 'Hawaiian Standard Time',
		'Pacific/Wallis' => 'UTC+12',
		// UTC
		'UTC' => 'UTC',
		'Etc/UTC' => 'UTC',
		'Etc/GMT' => 'UTC',
		'GMT' => 'UTC',
	];

	/**
	 * @return string Windows id; "UTC" when the zone is unknown
	 */
	public static function toWindows(?string $iana): string {
		if ($iana === null || $iana === '') {
			return 'UTC';
		}
		return self::MAP[$iana] ?? 'UTC';
	}

	/**
	 * An IANA zone for a Windows id (« Romance Standard Time » → « Europe/Paris »):
	 * the first of the map, null when unknown.
	 */
	public static function toIana(string $windows): ?string {
		$found = array_search($windows, self::MAP, true);
		return $found === false ? null : $found;
	}

	public static function isValidIana(?string $iana): bool {
		return $iana !== null && $iana !== '' && in_array($iana, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
	}
}
