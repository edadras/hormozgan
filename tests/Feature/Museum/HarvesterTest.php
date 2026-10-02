<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\Media;
use App\Museum\Importers\WikimediaHarvester;

/** Parsers are tested on small synthetic HTML shaped like MediaWiki output (no real content). */
class HarvesterTest extends MuseumTestCase
{
    private function h(): WikimediaHarvester
    {
        return app(WikimediaHarvester::class);
    }

    public function test_category_hints_use_whole_words(): void
    {
        $this->assertSame('food', $this->h()->hintFor('رده:آشپزی_استان_هرمزگان'));
        $this->assertSame('village', $this->h()->hintFor('رده:روستاهای_شهرستان_رودان'));
        $this->assertSame('natural_phenomenon', $this->h()->hintFor('رده:بادهای_موسمی_استان_هرمزگان'));
        $this->assertNull($this->h()->hintFor('رده:شهرستان_حاجی‌آباد')); // «آباد» is not «باد»
        $this->assertNull($this->h()->hintFor('رده:شهرستان_رودان'));       // «رودان» is not «رود»
    }

    public function test_category_parsing_ignores_parent_links(): void
    {
        $html = '<div id="mw-subcategories"><ul><li><a href="/wiki/%D8%B1%D8%AF%D9%87:Fixture_Sub">x</a></li></ul></div>'
            .'<div id="mw-pages"><ul><li><a href="/wiki/Fixture_Article">a</a></li><li><a href="/wiki/Help:X">h</a></li></ul></div>'
            .'<div id="catlinks"><a href="/wiki/%D8%B1%D8%AF%D9%87:Parent">p</a></div>';
        [$sub, $pages] = $this->h()->parseCategory($html);
        $this->assertSame(['رده:Fixture_Sub'], $sub);
        $this->assertSame(['Fixture Article'], $pages);
    }

    public function test_article_parsing_keeps_text_verbatim(): void
    {
        $html = '<script>"wgTitle":"Fixture Dish","wgRevisionId":123,"wgWikibaseItemId":"Q900777"</script>'
            .'<div class="mw-content-rtl mw-parser-output"><table class="infobox"><tr><td>box</td></tr></table>'
            .'<p>Fixture Dish is a dish described in this test paragraph.<sup class="reference">[۱]</sup></p>'
            .'<h2 id="x">طرز تهیه</h2><p>Boil the fixture ingredients slowly for one hour today.</p>'
            .'<h2>منابع</h2><p>Some reference list text that is long enough.</p></div><div class="printfooter"></div>';
        $m = $this->h()->parseArticle($html);
        $this->assertSame('Q900777', $m['qid']);
        $this->assertSame('123', $m['revision']);
        $this->assertSame(['Fixture Dish is a dish described in this test paragraph.'], $m['lead']);
        $this->assertSame(['Boil the fixture ingredients slowly for one hour today.'], $m['sections']['طرز تهیه']);
    }

    public function test_commons_licence_parsing_and_gate(): void
    {
        // The <head> link is the page-text licence and must be ignored; the file licence is in licensetpl fields.
        $html = '<link rel="license" href="https://creativecommons.org/licenses/by-sa/4.0/">'
            .'<span class="licensetpl&#95;link" style="display:none;">https://creativecommons.org/licenses/by/4.0</span>'
            .'<span class="licensetpl&#95;short" style="display:none;">CC BY 4.0 </span>'
            .'<td id="fileinfotpl&#95;aut">Author</td><td><a>Fixture Photographer</a></td>'
            .'<td id="fileinfotpl&#95;date">Date</td><td>12 March 1975</td>';
        $info = $this->h()->parseCommons($html);
        $this->assertSame('cc_by', $info['license']);
        $this->assertSame('Fixture Photographer', $info['author']);
        $this->assertSame(1975, $info['year']);

        $this->assertSame('unknown', $this->h()->parseCommons('<link rel="license" href="https://creativecommons.org/licenses/by-sa/4.0/">')['license']);
        $this->assertSame('public_domain', $this->h()->parseCommons('<span class="licensetpl_short">Public domain</span>')['license']);

        $m = Media::create(['media_type' => 'image', 'disk' => 'remote', 'path' => 'https://upload.wikimedia.org/x.jpg', 'license' => 'cc_by_sa',
            'visibility' => 'published', 'variants' => ['thumb' => 'https://upload.wikimedia.org/t.jpg']]);
        $this->assertSame('https://upload.wikimedia.org/t.jpg', $m->publicUrl('thumb'));
        $m->update(['license' => 'unknown']);
        $this->assertNull($m->fresh()->publicUrl());
    }
}
