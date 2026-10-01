<?php

namespace App\Museum\Support;

/** String and geographic similarity measures used by entity resolution and dedup. */
final class Similarity
{
    /** Dice coefficient over character trigrams of the compact normalized forms (0..1). */
    public static function trigramDice(string $a, string $b): float
    {
        $ta = TextNormalizer::trigrams($a);
        $tb = TextNormalizer::trigrams($b);
        if (! $ta || ! $tb) {
            return 0.0;
        }
        $common = count(array_intersect($ta, $tb));

        return (2 * $common) / (count($ta) + count($tb));
    }

    /** Jaro-Winkler similarity on compact normalized forms (multibyte-safe). */
    public static function jaroWinkler(string $a, string $b): float
    {
        $s1 = mb_str_split(TextNormalizer::compact($a));
        $s2 = mb_str_split(TextNormalizer::compact($b));
        $l1 = count($s1);
        $l2 = count($s2);
        if ($l1 === 0 || $l2 === 0) {
            return 0.0;
        }
        if ($s1 === $s2) {
            return 1.0;
        }
        $window = max(0, intdiv(max($l1, $l2), 2) - 1);
        $m1 = array_fill(0, $l1, false);
        $m2 = array_fill(0, $l2, false);
        $matches = 0;
        for ($i = 0; $i < $l1; $i++) {
            $start = max(0, $i - $window);
            $end = min($i + $window + 1, $l2);
            for ($j = $start; $j < $end; $j++) {
                if (! $m2[$j] && $s1[$i] === $s2[$j]) {
                    $m1[$i] = $m2[$j] = true;
                    $matches++;
                    break;
                }
            }
        }
        if ($matches === 0) {
            return 0.0;
        }
        $t = 0;
        $k = 0;
        for ($i = 0; $i < $l1; $i++) {
            if ($m1[$i]) {
                while (! $m2[$k]) {
                    $k++;
                }
                if ($s1[$i] !== $s2[$k]) {
                    $t++;
                }
                $k++;
            }
        }
        $t /= 2;
        $jaro = ($matches / $l1 + $matches / $l2 + ($matches - $t) / $matches) / 3;
        $prefix = 0;
        for ($i = 0; $i < min(4, $l1, $l2); $i++) {
            if ($s1[$i] !== $s2[$i]) {
                break;
            }
            $prefix++;
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }

    /** Combined name score: the stronger of the two measures, damped for very short strings. */
    public static function name(string $a, string $b): float
    {
        if (TextNormalizer::compact($a) === TextNormalizer::compact($b)) {
            return 1.0;
        }
        $score = max(self::jaroWinkler($a, $b) * 0.95, self::trigramDice($a, $b));
        $len = min(mb_strlen(TextNormalizer::compact($a)), mb_strlen(TextNormalizer::compact($b)));

        return $len < 4 ? $score * 0.85 : $score;
    }

    /** Great-circle distance in kilometres. */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($h)));
    }
}
