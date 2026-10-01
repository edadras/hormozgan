<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\Fact;
use App\Models\Museum\Media;
use App\Models\User;
use App\Museum\Services\FactService;
use App\Museum\Services\VerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class WebAndAdminTest extends MuseumTestCase
{
    private function published()
    {
        $e = $this->entity('city', 'بندرعباس', ['name_fa' => 'بندرعباس', 'name_en' => 'Bandar Abbas', 'latitude' => 27.18, 'longitude' => 56.27]);
        app(FactService::class)->assert($e, 'population', 526648, ['source' => $this->source(), 'status' => 'source_verified', 'qualifiers' => ['census_year' => 2016]]);
        app(VerificationService::class)->publish($e->fresh(), null);

        return $e->fresh();
    }

    public function test_public_pages_render(): void
    {
        $e = $this->published();
        $fact = Fact::sole();
        foreach (['/museum', '/museum/places', '/museum/language', '/museum/language/compare', '/museum/language/grammar', '/museum/history',
            '/museum/history/map', '/museum/sea', '/museum/sea/lenj', '/museum/food', '/museum/culture', '/museum/explore', '/museum/kids',
            '/museum/contribute', '/museum/ask', '/museum/search?q=bandar', '/museum/about/crawler', '/museum/e/'.$e->slug,
            '/museum/facts/'.$fact->uuid, '/museum/sources/'.$fact->sources()->first()->source->uuid] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/museum/e/'.$e->slug)->assertSee('مشاهده منبع')->assertSee('آمار سال 2016');
        $this->get('/')->assertRedirect('/museum');
    }

    public function test_drafts_are_hidden_from_public(): void
    {
        $draft = $this->entity('village', 'Draft Fixture');
        $this->get('/museum/e/'.$draft->slug)->assertNotFound();
    }

    public function test_contribute_form_creates_submission(): void
    {
        Queue::fake();
        $this->post('/museum/contribute', ['submission_type' => 'word', 'text' => 'fixtureword', 'meaning' => 'm', 'consent_publish' => '1'])
            ->assertRedirect('/museum/contribute');
        $this->assertDatabaseHas('museum_community_submissions', ['submission_type' => 'word', 'status' => 'submitted']);
        $this->post('/museum/contribute', ['submission_type' => 'word', 'text' => 'x', 'consent_publish' => '1', 'website' => 'spam'])
            ->assertSessionHasErrors('website');
    }

    public function test_admin_requires_login_and_role(): void
    {
        $this->get('/museum/admin')->assertRedirect('/museum/admin/login');
        $this->actingAs(User::factory()->create())->get('/museum/admin')->assertForbidden();
        $this->actingAs($this->userWithRole('editor'))->get('/museum/admin')->assertOk()->assertSee('داشبورد کیفیت داده');
    }

    public function test_admin_pages_render(): void
    {
        $e = $this->published();
        $this->actingAs($this->userWithRole('admin'));
        foreach (['verification', 'conflicts', 'duplicates', 'candidates', 'sources', 'imports', 'ai-jobs', 'submissions', 'entities',
            'entities/'.$e->slug, 'media', 'research', 'sources/'.$e->facts()->first()->sources()->first()->source->uuid] as $page) {
            $this->get('/museum/admin/'.$page)->assertOk();
        }
    }

    public function test_moderator_cannot_expert_verify(): void
    {
        $e = $this->published();
        $fact = $e->facts()->first();
        $this->actingAs($this->userWithRole('moderator'))
            ->post('/museum/admin/facts/'.$fact->uuid.'/status', ['status' => 'expert_verified'])->assertForbidden();
        $this->actingAs($this->userWithRole('expert'))
            ->post('/museum/admin/facts/'.$fact->uuid.'/status', ['status' => 'expert_verified'])->assertRedirect();
        $this->assertSame('expert_verified', $fact->fresh()->verification_status);
    }

    public function test_restricted_media_is_never_served(): void
    {
        Storage::fake('museum_media');
        $this->actingAs($this->userWithRole('editor'));
        $this->post('/museum/admin/media', ['file' => UploadedFile::fake()->image('old.jpg'), 'license' => 'unknown', 'title' => 'Fixture photo'])->assertRedirect();
        $m = Media::sole();
        $this->post('/museum/admin/media/'.$m->uuid, ['visibility' => 'published'])->assertSessionHas('status');
        $this->assertSame('draft', $m->fresh()->visibility);
        $this->get('/museum/files/'.$m->uuid)->assertForbidden();

        $m->update(['license' => 'cc_by', 'visibility' => 'published']);
        $this->get('/museum/files/'.$m->uuid)->assertOk();
    }
}
