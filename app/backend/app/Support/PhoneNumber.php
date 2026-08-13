<?php

namespace App\Support;

/**
 * Normalises a WooCommerce phone number to E.164 (+<country><subscriber>), which
 * is what Shopify accepts.
 *
 * WooCommerce stores whatever the shopper typed — "0300 1234567", "(021) 111-222",
 * "0092 300 1234567". Shopify rejects all of those with "Phone is invalid", which
 * used to fail the whole customer. The billing country tells us the calling code
 * the number is missing.
 *
 * This is deliberately a normaliser, not a validator: it makes a well-formed
 * number where it reasonably can and returns null where it can't, so the caller
 * can drop the field rather than send something Shopify will reject.
 */
class PhoneNumber
{
    /**
     * Countries where a leading 0 is part of the subscriber number and must be
     * kept rather than treated as a national trunk prefix.
     */
    private const KEEPS_LEADING_ZERO = ['IT', 'VA'];

    /**
     * National trunk prefix, where it isn't the usual "0". Getting this wrong
     * silently mangles the number: a Russian "8 912 345 6789" became
     * +789123456789 instead of +79123456789.
     */
    private const TRUNK_PREFIXES = [
        'RU' => '8', 'KZ' => '8', 'BY' => '8', 'LT' => '8', 'UZ' => '8', 'TM' => '8',
        'HU' => '06',
    ];

    /** ISO 3166-1 alpha-2 => country calling code. */
    private const CALLING_CODES = [
        'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1268', 'AI' => '1264', 'AL' => '355',
        'AM' => '374', 'AO' => '244', 'AR' => '54', 'AS' => '1684', 'AT' => '43', 'AU' => '61',
        'AW' => '297', 'AZ' => '994', 'BA' => '387', 'BB' => '1246', 'BD' => '880', 'BE' => '32',
        'BF' => '226', 'BG' => '359', 'BH' => '973', 'BI' => '257', 'BJ' => '229', 'BM' => '1441',
        'BN' => '673', 'BO' => '591', 'BR' => '55', 'BS' => '1242', 'BT' => '975', 'BW' => '267',
        'BY' => '375', 'BZ' => '501', 'CA' => '1', 'CD' => '243', 'CF' => '236', 'CG' => '242',
        'CH' => '41', 'CI' => '225', 'CL' => '56', 'CM' => '237', 'CN' => '86', 'CO' => '57',
        'CR' => '506', 'CU' => '53', 'CV' => '238', 'CW' => '599', 'CY' => '357', 'CZ' => '420',
        'DE' => '49', 'DJ' => '253', 'DK' => '45', 'DM' => '1767', 'DO' => '1809', 'DZ' => '213',
        'EC' => '593', 'EE' => '372', 'EG' => '20', 'ER' => '291', 'ES' => '34', 'ET' => '251',
        'FI' => '358', 'FJ' => '679', 'FO' => '298', 'FR' => '33', 'GA' => '241', 'GB' => '44',
        'GD' => '1473', 'GE' => '995', 'GF' => '594', 'GG' => '44', 'GH' => '233', 'GI' => '350',
        'GL' => '299', 'GM' => '220', 'GN' => '224', 'GP' => '590', 'GQ' => '240', 'GR' => '30',
        'GT' => '502', 'GU' => '1671', 'GW' => '245', 'GY' => '592', 'HK' => '852', 'HN' => '504',
        'HR' => '385', 'HT' => '509', 'HU' => '36', 'ID' => '62', 'IE' => '353', 'IL' => '972',
        'IM' => '44', 'IN' => '91', 'IQ' => '964', 'IR' => '98', 'IS' => '354', 'IT' => '39',
        'JE' => '44', 'JM' => '1876', 'JO' => '962', 'JP' => '81', 'KE' => '254', 'KG' => '996',
        'KH' => '855', 'KI' => '686', 'KM' => '269', 'KN' => '1869', 'KP' => '850', 'KR' => '82',
        'KW' => '965', 'KY' => '1345', 'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1758',
        'LI' => '423', 'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370', 'LU' => '352',
        'LV' => '371', 'LY' => '218', 'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382',
        'MG' => '261', 'MK' => '389', 'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853',
        'MQ' => '596', 'MR' => '222', 'MT' => '356', 'MU' => '230', 'MV' => '960', 'MW' => '265',
        'MX' => '52', 'MY' => '60', 'MZ' => '258', 'NA' => '264', 'NC' => '687', 'NE' => '227',
        'NG' => '234', 'NI' => '505', 'NL' => '31', 'NO' => '47', 'NP' => '977', 'NZ' => '64',
        'OM' => '968', 'PA' => '507', 'PE' => '51', 'PF' => '689', 'PG' => '675', 'PH' => '63',
        'PK' => '92', 'PL' => '48', 'PR' => '1787', 'PS' => '970', 'PT' => '351', 'PY' => '595',
        'QA' => '974', 'RE' => '262', 'RO' => '40', 'RS' => '381', 'RU' => '7', 'RW' => '250',
        'SA' => '966', 'SB' => '677', 'SC' => '248', 'SD' => '249', 'SE' => '46', 'SG' => '65',
        'SI' => '386', 'SK' => '421', 'SL' => '232', 'SM' => '378', 'SN' => '221', 'SO' => '252',
        'SR' => '597', 'SS' => '211', 'SV' => '503', 'SY' => '963', 'SZ' => '268', 'TC' => '1649',
        'TD' => '235', 'TG' => '228', 'TH' => '66', 'TJ' => '992', 'TL' => '670', 'TM' => '993',
        'TN' => '216', 'TO' => '676', 'TR' => '90', 'TT' => '1868', 'TW' => '886', 'TZ' => '255',
        'UA' => '380', 'UG' => '256', 'US' => '1', 'UY' => '598', 'UZ' => '998', 'VC' => '1784',
        'VE' => '58', 'VG' => '1284', 'VI' => '1340', 'VN' => '84', 'VU' => '678', 'WS' => '685',
        'YE' => '967', 'YT' => '262', 'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
    ];

    /**
     * @param  string|null  $raw  whatever WooCommerce had
     * @param  string|null  $countryCode  ISO 3166-1 alpha-2 from the billing address
     * @return string|null  E.164 (+123…), or null when it cannot be made valid
     */
    public static function toE164(?string $raw, ?string $countryCode): ?string
    {
        $raw = self::withoutExtension(trim((string) $raw));

        if ($raw === '') {
            return null;
        }

        // Already written as international — trust it.
        if (str_starts_with($raw, '+')) {
            return self::finish(preg_replace('/\D/', '', $raw));
        }

        $digits = preg_replace('/\D/', '', $raw);

        if ($digits === '') {
            return null;
        }

        // "00" is the international access prefix in most of the world.
        if (str_starts_with($digits, '00')) {
            return self::finish(substr($digits, 2));
        }

        $country = strtoupper(trim((string) $countryCode));
        $callingCode = self::CALLING_CODES[$country] ?? null;

        // Without a country we cannot know which code is missing, and guessing
        // would invent a wrong number. Better to send nothing.
        if ($callingCode === null) {
            return null;
        }

        // Already carries the calling code without the "+" (e.g. 923001234567).
        if (str_starts_with($digits, $callingCode) && strlen($digits) >= strlen($callingCode) + 7) {
            return self::finish($digits);
        }

        // Drop the national trunk prefix — E.164 never includes it.
        $trunk = self::TRUNK_PREFIXES[$country] ?? '0';

        if (str_starts_with($digits, $trunk) && ! in_array($country, self::KEEPS_LEADING_ZERO, true)) {
            $digits = substr($digits, strlen($trunk));
        }

        return self::finish($callingCode.$digits);
    }

    /**
     * "310-555-1234 ext 22" must not become +1310555123422. Only strips when the
     * marker is followed by digits at the very end, so it can't eat a real number.
     */
    private static function withoutExtension(string $raw): string
    {
        return (string) preg_replace('/\s*(?:e?xt\.?|extension|x|#|,|;)\s*\d+\s*$/i', '', $raw);
    }

    /** E.164 allows at most 15 digits; anything under 8 is not a reachable number. */
    private static function finish(string $digits): ?string
    {
        $length = strlen($digits);

        return $length >= 8 && $length <= 15 ? '+'.$digits : null;
    }
}
