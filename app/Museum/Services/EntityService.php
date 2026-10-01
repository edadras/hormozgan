<?php

namespace App\Museum\Services;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityAlias;
use App\Models\Museum\EntityType;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Place;
use App\Museum\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates entities with their extension rows and keeps the alias index complete.
 * Every name field (canonical, fa, en, ar, local) is mirrored into museum_entity_aliases
 * so that resolution and search see all spellings.
 */
class EntityService
{
    private const NAME_FIELDS = [
        'canonical_name' => ['canonical', null],
        'name_fa' => ['canonical', 'fa'],
        'name_en' => ['transliteration', 'en'],
        'name_ar' => ['spelling_variant', 'ar'],
        'name_local' => ['local', 'local'],
        'name_local_latin' => ['transliteration', 'local'],
    ];

    /**
     * @param  array  $attributes  museum_entities columns (canonical_name required)
     * @param  array  $extension  columns for the type's extension table
     */
    public function create(string $typeKey, array $attributes, array $extension = [], ?int $userId = null): Entity
    {
        if (empty($attributes['canonical_name'])) {
            throw new \InvalidArgumentException('canonical_name is required');
        }
        $type = EntityType::findOrFail(EntityType::idFor($typeKey));

        return DB::transaction(function () use ($type, $attributes, $extension, $userId) {
            $entity = new Entity(array_merge([
                'verification_status' => 'unverified',
                'visibility' => 'draft',
            ], $attributes));
            $entity->entity_type_id = $type->id;
            $entity->normalized_name = TextNormalizer::normalize($attributes['canonical_name']);
            $entity->slug = $this->uniqueSlug($attributes['name_en'] ?? $attributes['canonical_name']);
            $entity->created_by = $userId;
            $entity->save();

            $this->syncNameAliases($entity);
            $this->createExtension($entity, $type, $extension);

            return $entity;
        });
    }

    public function update(Entity $entity, array $attributes, array $extension = []): Entity
    {
        return DB::transaction(function () use ($entity, $attributes, $extension) {
            $entity->fill($attributes);
            if ($entity->isDirty('canonical_name')) {
                $entity->normalized_name = TextNormalizer::normalize($entity->canonical_name);
            }
            $entity->save();
            $this->syncNameAliases($entity);
            if ($extension) {
                $type = $entity->type;
                $this->createExtension($entity, $type, $extension);
            }

            return $entity;
        });
    }

    public function addAlias(Entity $entity, string $alias, array $options = []): ?EntityAlias
    {
        $alias = trim($alias);
        $normalized = TextNormalizer::normalize($alias);
        if ($normalized === '') {
            return null;
        }
        $language = $options['language'] ?? null;

        return EntityAlias::firstOrCreate(
            ['entity_id' => $entity->id, 'normalized_alias' => $normalized, 'language' => $language],
            [
                'alias' => $alias,
                'compact_key' => TextNormalizer::compact($alias),
                'script' => TextNormalizer::script($alias),
                'alias_type' => $options['type'] ?? 'spelling_variant',
                'is_primary' => $options['primary'] ?? false,
                'source_id' => $options['source_id'] ?? null,
            ]
        );
    }

    /**
     * Records a historical name and also indexes it as an alias so searches for
     * old names find the current entity.
     */
    public function addHistoricalName(Entity $entity, array $data): HistoricalName
    {
        $data['normalized_name'] = TextNormalizer::normalize($data['name']);
        $hn = HistoricalName::firstOrCreate(
            ['entity_id' => $entity->id, 'normalized_name' => $data['normalized_name'], 'source_id' => $data['source_id'] ?? null],
            $data
        );
        $this->addAlias($entity, $data['name'], ['type' => 'historical', 'language' => $data['language'] ?? null, 'source_id' => $data['source_id'] ?? null]);

        return $hn;
    }

    public function syncNameAliases(Entity $entity): void
    {
        foreach (self::NAME_FIELDS as $field => [$type, $lang]) {
            if (! empty($entity->{$field})) {
                $this->addAlias($entity, $entity->{$field}, [
                    'type' => $type,
                    'language' => $lang ?? (TextNormalizer::script($entity->{$field}) === 'Latn' ? 'en' : 'fa'),
                    'primary' => $field === 'canonical_name',
                ]);
            }
        }
    }

    /** Places keep a materialized ancestry path for fast hierarchical queries. */
    public function setPlaceParent(Entity $entity, ?Entity $parent): void
    {
        $place = Place::find($entity->id);
        if (! $place) {
            return;
        }
        if ($parent && $parent->id === $entity->id) {
            throw new \InvalidArgumentException('A place cannot be its own parent.');
        }
        $parentPlace = $parent ? Place::find($parent->id) : null;
        if ($parentPlace && str_contains((string) $parentPlace->path, '/'.$entity->id.'/')) {
            throw new \InvalidArgumentException('Cycle detected in place hierarchy.');
        }
        $oldPath = $place->path;
        $place->parent_id = $parent?->id;
        $place->path = ($parentPlace?->path ?: '/').$entity->id.'/';
        $place->depth = substr_count($place->path, '/') - 2;
        $place->save();

        // Re-root descendants.
        if ($oldPath && $oldPath !== $place->path) {
            Place::where('path', 'like', $oldPath.'%')->where('entity_id', '!=', $entity->id)->get()
                ->each(function (Place $child) use ($oldPath, $place) {
                    $child->path = $place->path.substr($child->path, strlen($oldPath));
                    $child->depth = substr_count($child->path, '/') - 2;
                    $child->save();
                });
        }
    }

    public function uniqueSlug(string $name): string
    {
        $base = TextNormalizer::slug(Str::ascii($name) !== '' && TextNormalizer::script($name) === 'Latn' ? Str::slug($name) : $name);
        $slug = $base;
        $i = 2;
        while (Entity::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function createExtension(Entity $entity, EntityType $type, array $extension): void
    {
        $table = $type->extension_table;
        if (! $table) {
            return;
        }
        $isPlace = $table === 'museum_places';
        if ($isPlace) {
            $extension['place_type'] = $extension['place_type'] ?? ($type->key === 'place' ? 'region' : $type->key);
        }
        if ($table === 'museum_music_items') {
            $extension['music_kind'] = $extension['music_kind'] ?? str_replace('music_', '', $type->key);
        }
        $now = now();
        $exists = DB::table($table)->where('entity_id', $entity->id)->exists();
        $parentId = $extension['parent_id'] ?? null;
        if ($isPlace) {
            unset($extension['parent_id']);
        }
        if ($exists) {
            if ($extension) {
                DB::table($table)->where('entity_id', $entity->id)->update($extension + ['updated_at' => $now]);
            }
        } else {
            DB::table($table)->insert(array_merge($extension, ['entity_id' => $entity->id, 'created_at' => $now, 'updated_at' => $now]));
        }
        if ($isPlace && ($parentId || ! $exists)) {
            $this->setPlaceParent($entity, $parentId ? Entity::find($parentId) : null);
        }
    }
}
