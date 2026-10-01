<?php

namespace Tests\Unit\Museum;

use App\Museum\Support\Similarity;
use App\Museum\Support\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    public static function bandarAbbasSpellings(): array
    {
        return [['بندرعباس'], ['بندر عباس'], ['بندر‌عباس'], ['بَندَر عبّاس']];
    }

    #[DataProvider('bandarAbbasSpellings')]
    public function test_persian_spellings_share_compact_key(string $spelling): void
    {
        $this->assertSame('بندرعباس', TextNormalizer::compact($spelling));
    }

    public function test_latin_transliterations_share_compact_key(): void
    {
        $this->assertSame('bandarabbas', TextNormalizer::compact('Bandar Abbas'));
        $this->assertSame('bandarabbas', TextNormalizer::compact('Bandar-e Abbas'));
        $this->assertSame('bandarabbas', TextNormalizer::compact('Bandar-e ‘Abbās'));
    }

    public function test_arabic_letters_and_digits_are_unified(): void
    {
        $this->assertSame('میناب', TextNormalizer::normalize('ميناب'));
        $this->assertSame('کشتی', TextNormalizer::normalize('كشتي'));
        $this->assertSame('1355', TextNormalizer::normalize('۱۳۵۵'));
        $this->assertSame('1355', TextNormalizer::normalize('١٣٥٥'));
    }

    public function test_zwnj_is_treated_as_space(): void
    {
        $this->assertSame('می رود', TextNormalizer::normalize('می‌رود'));
    }

    public function test_script_detection(): void
    {
        $this->assertSame('Arab', TextNormalizer::script('قشم'));
        $this->assertSame('Latn', TextNormalizer::script('Qeshm'));
        $this->assertNull(TextNormalizer::script('123'));
    }

    public function test_similarity(): void
    {
        $this->assertSame(1.0, Similarity::name('Bandar Abbas', 'Bandar-e Abbas'));
        $this->assertGreaterThan(0.8, Similarity::name('میناب', 'میناو'));
        $this->assertLessThan(0.5, Similarity::name('قشم', 'کیش'));
    }

    public function test_haversine(): void
    {
        // One degree of latitude ≈ 111 km.
        $this->assertEqualsWithDelta(111.2, Similarity::haversineKm(27.0, 56.0, 28.0, 56.0), 0.5);
    }
}
