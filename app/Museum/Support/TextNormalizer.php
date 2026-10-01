<?php

namespace App\Museum\Support;

/**
 * Normalization shared by entity resolution, deduplication and search indexing.
 *
 * Normalized text is only used for matching; original spellings are always kept.
 *  - Arabic/Persian letter unification (ي→ی, ك→ک, ة/ۀ→ه, أ/إ/آ→ا, ؤ→و, ئ→ی)
 *  - diacritics, tatweel and zero-width characters removed
 *  - Persian/Arabic digits → ASCII digits
 *  - Latin: lowercase, accents stripped, Persian ezafe connectors ("-e", "-ye") dropped
 *  - punctuation/hyphens/ZWNJ → single spaces
 */
final class TextNormalizer
{
    private const LETTER_MAP = [
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'ہ' => 'ه',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و', 'ڪ' => 'ک', 'ۍ' => 'ی', 'ې' => 'ی',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $t = strtr($text, self::LETTER_MAP);
        // Arabic diacritics (harakat), superscript alef, Quranic marks, tatweel.
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $t);
        // Zero-width chars: ZWNJ/ZWJ become spaces so "می‌رود" == "می رود"; others are dropped.
        $t = preg_replace('/[\x{200C}\x{200D}]/u', ' ', $t);
        $t = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{FEFF}\x{202A}-\x{202E}]/u', '', $t);
        $t = self::foldLatin($t);
        $t = mb_strtolower($t, 'UTF-8');
        // Punctuation (Latin + Arabic: ، ؛ ؟ « ») and symbols to spaces; keep letters, marks and digits.
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);
        // Ezafe connectors in Latin transliterations: "bandar e abbas" / "bandar ye ..." → "bandar abbas".
        $t = preg_replace('/(?<=\p{Latin})\s+(?:e|ye|-e|-ye)\s+(?=\p{Latin})/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);

        return trim($t);
    }

    /** Normalized form without spaces: "بندر عباس" == "بندرعباس", "Bandar-e Abbas" == "bandarabbas". */
    public static function compact(?string $text): string
    {
        return str_replace(' ', '', self::normalize($text));
    }

    /** Strip accents from Latin letters only; Perso-Arabic letters are untouched. */
    private static function foldLatin(string $t): string
    {
        if (! preg_match('/[\x{00C0}-\x{024F}\x{1E00}-\x{1EFF}]/u', $t)) {
            return $t;
        }
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($t, \Normalizer::FORM_D);
            if ($d !== false) {
                // Remove combining marks that follow Latin base letters.
                $t = preg_replace('/(?<=\p{Latin})\p{Mn}+/u', '', $d);
                $t = \Normalizer::normalize($t, \Normalizer::FORM_C) ?: $t;
            }
        }

        return $t;
    }

    /** Converts Persian/Arabic-Indic digits to ASCII, leaving everything else intact. */
    public static function asciiDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    public static function script(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
        $arab = preg_match_all('/\p{Arabic}/u', $text);
        $latn = preg_match_all('/\p{Latin}/u', $text);
        if ($arab === 0 && $latn === 0) {
            return null;
        }

        return $arab >= $latn ? 'Arab' : 'Latn';
    }

    /** @return list<string> */
    public static function tokens(?string $text): array
    {
        $n = self::normalize($text);

        return $n === '' ? [] : array_values(array_filter(explode(' ', $n), fn ($w) => $w !== ''));
    }

    /** Character trigrams of the compact form, padded. */
    public static function trigrams(?string $text): array
    {
        $s = '  '.self::compact($text).' ';
        $len = mb_strlen($s);
        $grams = [];
        for ($i = 0; $i < $len - 2; $i++) {
            $grams[] = mb_substr($s, $i, 3);
        }

        return array_values(array_unique($grams));
    }

    /** URL slug that keeps Persian letters readable. */
    public static function slug(string $text): string
    {
        $n = self::normalize($text);
        $slug = preg_replace('/\s+/u', '-', $n);
        $slug = trim(mb_substr($slug, 0, 120), '-');

        return $slug !== '' ? $slug : 'item';
    }
}
