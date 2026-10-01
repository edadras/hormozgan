<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\Entity;
use App\Models\Museum\Role;
use App\Models\Museum\Source;
use App\Models\User;
use App\Museum\Services\EntityService;
use App\Museum\Services\SourceService;
use Database\Seeders\MuseumSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test helpers. Content created here exists only inside the test database and is
 * labelled as test fixtures; it never reaches seeders or production.
 */
abstract class MuseumTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MuseumSystemSeeder::class);
    }

    protected function entity(string $type, string $name, array $attrs = [], array $ext = []): Entity
    {
        return app(EntityService::class)->create($type, ['canonical_name' => $name] + $attrs, $ext);
    }

    protected function source(array $attrs = []): Source
    {
        return app(SourceService::class)->register($attrs + [
            'source_type' => 'article',
            'title' => 'Test fixture source '.uniqid(),
            'reliability_tier' => 4,
        ]);
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->museumRoles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }
}
