/**
 * Analytics helpers.
 * - trackToolUsage: fire-and-forget beacon after a tool actually processes.
 * - Country hint: once per session, share the browser's IANA timezone mapped
 *   to an ISO country (Asia/Dhaka -> BD). Timezone reflects the visitor's OS
 *   setting and is far more accurate than the browser language: most
 *   English-language browsers report "en-US" regardless of the user's real
 *   country. Approximate only; edge headers (Cloudflare) take priority.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

export function trackToolUsage(componentKey, success = true, event = 'run') {
    try {
        const csrf = csrfToken();
        if (!csrf) return;

        const body = new URLSearchParams({ tool: componentKey, success: String(success), event });
        body.append('_token', csrf);

        fetch('/api/tool-usage', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            keepalive: true,
        }).catch(() => {});
    } catch {
        /* analytics must never break tools */
    }
}

/**
 * IANA timezone -> ISO 3166-1 alpha-2. Zones not listed produce no hint
 * (better "Unknown" than a wrong country).
 */
const TIMEZONE_COUNTRIES = {
    // Asia
    'Asia/Dhaka': 'BD', 'Asia/Karachi': 'PK', 'Asia/Kolkata': 'IN', 'Asia/Calcutta': 'IN',
    'Asia/Colombo': 'LK', 'Asia/Kathmandu': 'NP', 'Asia/Thimphu': 'BT', 'Asia/Yangon': 'MM',
    'Asia/Tokyo': 'JP', 'Asia/Seoul': 'KR', 'Asia/Pyongyang': 'KP', 'Asia/Shanghai': 'CN',
    'Asia/Chongqing': 'CN', 'Asia/Harbin': 'CN', 'Asia/Urumqi': 'CN', 'Asia/Kashgar': 'CN',
    'Asia/Hong_Kong': 'HK', 'Asia/Macau': 'MO', 'Asia/Taipei': 'TW', 'Asia/Singapore': 'SG',
    'Asia/Kuala_Lumpur': 'MY', 'Asia/Kuching': 'MY', 'Asia/Jakarta': 'ID', 'Asia/Pontianak': 'ID',
    'Asia/Makassar': 'ID', 'Asia/Jayapura': 'ID', 'Asia/Manila': 'PH', 'Asia/Bangkok': 'TH',
    'Asia/Ho_Chi_Minh': 'VN', 'Asia/Phnom_Penh': 'KH', 'Asia/Vientiane': 'LA', 'Asia/Hanoi': 'VN',
    'Asia/Tashkent': 'UZ', 'Asia/Almaty': 'KZ', 'Asia/Atyrau': 'KZ', 'Asia/Kostanay': 'KZ',
    'Asia/Qyzylorda': 'KZ', 'Asia/Bishkek': 'KG', 'Asia/Dushanbe': 'TJ', 'Asia/Ashgabat': 'TM',
    'Asia/Kabul': 'AF', 'Asia/Tehran': 'IR', 'Asia/Baghdad': 'IQ', 'Asia/Jerusalem': 'IL',
    'Asia/Amman': 'JO', 'Asia/Beirut': 'LB', 'Asia/Damascus': 'SY', 'Asia/Gaza': 'PS',
    'Asia/Hebron': 'PS', 'Asia/Dubai': 'AE', 'Asia/Muscat': 'OM', 'Asia/Doha': 'QA',
    'Asia/Qatar': 'QA', 'Asia/Kuwait': 'KW', 'Asia/Riyadh': 'SA', 'Asia/Bahrain': 'BH',
    'Asia/Aden': 'YE', 'Asia/Ulaanbaatar': 'MN', 'Asia/Choibalsan': 'MN', 'Asia/Yakutsk': 'RU',
    'Asia/Vladivostok': 'RU', 'Asia/Irkutsk': 'RU', 'Asia/Novosibirsk': 'RU', 'Asia/Omsk': 'RU',
    'Asia/Krasnoyarsk': 'RU', 'Asia/Yekaterinburg': 'RU', 'Asia/Tbilisi': 'GE', 'Asia/Yerevan': 'AM',
    'Asia/Baku': 'AZ', 'Asia/Kamchatka': 'RU', 'Asia/Anadyr': 'RU', 'Asia/Magadan': 'RU',
    'Asia/Sakhalin': 'RU', 'Asia/Ust-Nera': 'RU', 'Asia/Novokuznetsk': 'RU', 'Asia/Barnaul': 'RU',
    'Asia/Tomsk': 'RU', 'Asia/Aqtau': 'KZ', 'Asia/Aqtobe': 'KZ', 'Asia/Oral': 'KZ',
    'Asia/Samarkand': 'UZ', 'Asia/Tbilisi': 'GE', 'Asia/Famagusta': 'CY', 'Asia/Nicosia': 'CY',

    // Europe
    'Europe/London': 'GB', 'Europe/Dublin': 'IE', 'Europe/Paris': 'FR', 'Europe/Berlin': 'DE',
    'Europe/Madrid': 'ES', 'Europe/Rome': 'IT', 'Europe/Amsterdam': 'NL', 'Europe/Brussels': 'BE',
    'Europe/Vienna': 'AT', 'Europe/Zurich': 'CH', 'Europe/Stockholm': 'SE', 'Europe/Oslo': 'NO',
    'Europe/Copenhagen': 'DK', 'Europe/Helsinki': 'FI', 'Europe/Warsaw': 'PL', 'Europe/Prague': 'CZ',
    'Europe/Budapest': 'HU', 'Europe/Bucharest': 'RO', 'Europe/Sofia': 'BG', 'Europe/Athens': 'GR',
    'Europe/Lisbon': 'PT', 'Europe/Reykjavik': 'IS', 'Europe/Riga': 'LV', 'Europe/Tallinn': 'EE',
    'Europe/Vilnius': 'LT', 'Europe/Kyiv': 'UA', 'Europe/Kiev': 'UA', 'Europe/Chisinau': 'MD',
    'Europe/Minsk': 'BY', 'Europe/Belgrade': 'RS', 'Europe/Zagreb': 'HR', 'Europe/Ljubljana': 'SI',
    'Europe/Bratislava': 'SK', 'Europe/Tirane': 'AL', 'Europe/Podgorica': 'ME', 'Europe/Skopje': 'MK',
    'Europe/Sarajevo': 'BA', 'Europe/Monaco': 'MC', 'Europe/Vaduz': 'LI', 'Europe/Malta': 'MT',
    'Europe/Andorra': 'AD', 'Europe/San_Marino': 'SM', 'Europe/Vatican': 'VA', 'Europe/Luxembourg': 'LU',
    'Europe/Moscow': 'RU', 'Europe/Samara': 'RU', 'Europe/Volgograd': 'RU', 'Europe/Astrakhan': 'RU',
    'Europe/Simferopol': 'UA', 'Europe/Gibraltar': 'GI', 'Europe/Busingen': 'DE', 'Europe/Isle_of_Man': 'GB',
    'Europe/Jersey': 'GB', 'Europe/Guernsey': 'GB', 'Europe/Mariehamn': 'AX',

    // Americas
    'America/New_York': 'US', 'America/Detroit': 'US', 'America/Chicago': 'US',
    'America/Denver': 'US', 'America/Phoenix': 'US', 'America/Los_Angeles': 'US',
    'America/Anchorage': 'US', 'America/Boise': 'US', 'America/Indiana/Indianapolis': 'US',
    'America/Indiana/Marengo': 'US', 'America/Indiana/Petersburg': 'US',
    'America/Indiana/Tell_City': 'US', 'America/Indiana/Vevay': 'US', 'America/Indiana/Vincennes': 'US',
    'America/Indiana/Winamac': 'US', 'America/Kentucky/Louisville': 'US', 'America/Kentucky/Monticello': 'US',
    'America/Menominee': 'US', 'America/North_Dakota/Beulah': 'US', 'America/North_Dakota/Center': 'US',
    'America/North_Dakota/New_Salem': 'US', 'America/Juneau': 'US', 'America/Sitka': 'US',
    'America/Metlakatla': 'US', 'America/Nome': 'US', 'America/Yakutat': 'US', 'America/Adak': 'US',
    'Pacific/Honolulu': 'US', 'America/Toronto': 'CA', 'America/Vancouver': 'CA',
    'America/Winnipeg': 'CA', 'America/Halifax': 'CA', 'America/St_Johns': 'CA',
    'America/Montreal': 'CA', 'America/Edmonton': 'CA', 'America/Thunder_Bay': 'CA',
    'America/Regina': 'CA', 'America/Iqaluit': 'CA', 'America/Yellowknife': 'CA',
    'America/Mexico_City': 'MX', 'America/Cancun': 'MX', 'America/Tijuana': 'MX',
    'America/Merida': 'MX', 'America/Monterrey': 'MX', 'America/Sao_Paulo': 'BR',
    'America/Manaus': 'BR', 'America/Fortaleza': 'BR', 'America/Recife': 'BR',
    'America/Belem': 'BR', 'America/Brasilia': 'BR', 'America/Rio_Branco': 'BR',
    'America/Bahia': 'BR', 'America/Buenos_Aires': 'AR', 'America/Cordoba': 'AR',
    'America/Salta': 'AR', 'America/San_Luis': 'AR', 'America/Jujuy': 'AR',
    'America/Tucuman': 'AR', 'America/Catamarca': 'AR', 'America/Santiago': 'CL',
    'America/Bogota': 'CO', 'America/Lima': 'PE', 'America/Caracas': 'VE',
    'America/Guayaquil': 'EC', 'America/La_Paz': 'BO', 'America/Asuncion': 'PY',
    'America/Montevideo': 'UY', 'America/Panama': 'PA', 'America/Guatemala': 'GT',
    'America/Costa_Rica': 'CR', 'America/Havana': 'CU', 'America/Santo_Domingo': 'DO',
    'America/Port-au-Prince': 'HT', 'America/Jamaica': 'JM', 'America/Bridgetown': 'BB',
    'America/Port_of_Spain': 'TT', 'America/Tegucigalpa': 'HN', 'America/Managua': 'NI',
    'America/San_Salvador': 'SV', 'America/Nassau': 'BS', 'America/Barbados': 'BB',
    'America/Curacao': 'CW', 'America/Guyana': 'GY', 'America/Paramaribo': 'SR',
    'America/Grand_Turk': 'KY', 'America/Anguilla': 'AI', 'America/Antigua': 'AG',
    'America/Aruba': 'AW', 'America/Dominica': 'DM', 'America/Grenada': 'GD',
    'America/Montserrat': 'MS', 'America/St_Kitts': 'KN', 'America/St_Lucia': 'LC',
    'America/St_Vincent': 'VC', 'America/Tortola': 'VG', 'Atlantic/Bermuda': 'BM',

    // Africa
    'Africa/Lagos': 'NG', 'Africa/Cairo': 'EG', 'Africa/Johannesburg': 'ZA',
    'Africa/Nairobi': 'KE', 'Africa/Accra': 'GH', 'Africa/Casablanca': 'MA',
    'Africa/Algiers': 'DZ', 'Africa/Tunis': 'TN', 'Africa/Addis_Ababa': 'ET',
    'Africa/Dar_es_Salaam': 'TZ', 'Africa/Kampala': 'UG', 'Africa/Khartoum': 'SD',
    'Africa/Abidjan': 'CI', 'Africa/Dakar': 'SN', 'Africa/Kinshasa': 'CD',
    'Africa/Lubumbashi': 'CD', 'Africa/Luanda': 'AO', 'Africa/Harare': 'ZW',
    'Africa/Lusaka': 'ZM', 'Africa/Maputo': 'MZ', 'Africa/Tripoli': 'LY',
    'Africa/Bamako': 'ML', 'Africa/Ouagadougou': 'BF', 'Africa/Niamey': 'NE',
    'Africa/Ndjamena': 'TD', 'Africa/Djibouti': 'DJ', 'Africa/Mogadishu': 'SO',
    'Africa/Bangui': 'CF', 'Africa/Brazzaville': 'CG', 'Africa/Libreville': 'GA',
    'Africa/Douala': 'CM', 'Africa/Conakry': 'GN', 'Africa/Freetown': 'SL',
    'Africa/Monrovia': 'LR', 'Africa/Banjul': 'GM', 'Africa/Bissau': 'GW',
    'Africa/Nouakchott': 'MR', 'Africa/Porto-Novo': 'BJ', 'Africa/Lome': 'TG',
    'Africa/Windhoek': 'NA', 'Africa/Gaborone': 'BW', 'Africa/Maseru': 'LS',
    'Africa/Mbabane': 'SZ', 'Africa/Kigali': 'RW', 'Africa/Bujumbura': 'BI',
    'Africa/Asmara': 'ER', 'Africa/El_Aaiun': 'EH', 'Africa/Sao_Tome': 'ST',

    // Oceania & Atlantic
    'Australia/Sydney': 'AU', 'Australia/Melbourne': 'AU', 'Australia/Brisbane': 'AU',
    'Australia/Perth': 'AU', 'Australia/Adelaide': 'AU', 'Australia/Hobart': 'AU',
    'Australia/Darwin': 'AU', 'Australia/Broken_Hill': 'AU', 'Australia/Lindeman': 'AU',
    'Pacific/Auckland': 'NZ', 'Pacific/Chatham': 'NZ', 'Pacific/Fiji': 'FJ',
    'Pacific/Guam': 'GU', 'Pacific/Tahiti': 'PF', 'Pacific/Apia': 'WS',
    'Pacific/Tongatapu': 'TO', 'Pacific/Port_Moresby': 'PG', 'Pacific/Honiara': 'SB',
    'Pacific/Noumea': 'NC', 'Pacific/Tarawa': 'KI', 'Pacific/Majuro': 'MH',
    'Pacific/Palau': 'PW', 'Pacific/Efate': 'VU', 'Pacific/Pago_Pago': 'AS',
    'Pacific/Kiritimati': 'KI', 'Pacific/Guadalcanal': 'SB', 'Pacific/Nauru': 'NR',
    'Pacific/Wallis': 'WF', 'Pacific/Funafuti': 'TV', 'Pacific/Niue': 'NU',
    'Pacific/Rarotonga': 'CK', 'Pacific/Pohnpei': 'FM', 'Pacific/Chuuk': 'FM',
    'Pacific/Midway': 'UM', 'Pacific/Marquesas': 'PF', 'Pacific/Gambier': 'PF',
    'Atlantic/Azores': 'PT', 'Atlantic/Madeira': 'PT', 'Atlantic/Canary': 'ES',
    'Atlantic/Cape_Verde': 'CV', 'Atlantic/South_Georgia': 'GS', 'Atlantic/Stanley': 'FK',
    'Atlantic/Faroe': 'FO',

    // Middle East aliases
    'Europe/Istanbul': 'TR', 'Turkey': 'TR', 'Etc/UTC': null, 'UTC': null,
};

function timezoneCountry() {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        const country = TIMEZONE_COUNTRIES[zone];
        return typeof country === 'string' ? country : null;
    } catch {
        return null;
    }
}

function sendCountryHint() {
    try {
        if (sessionStorage.getItem('st-loc-sent')) return;
        sessionStorage.setItem('st-loc-sent', '1');

        const csrf = csrfToken();
        if (!csrf) return;

        const body = new URLSearchParams();
        const country = timezoneCountry();
        if (country) body.append('country', country);
        body.append('_token', csrf);

        fetch('/api/visitor-locale', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            keepalive: true,
        }).catch(() => {});
    } catch {
        /* never break the page */
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sendCountryHint);
} else {
    sendCountryHint();
}
