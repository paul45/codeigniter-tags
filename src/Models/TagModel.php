<?php

namespace Michalsn\CodeIgniterTags\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use Michalsn\CodeIgniterTags\Entities\Tag;
use Myth\Collection\Collection;
use ReflectionException;

class TagModel extends Model
{
    protected $table          = 'tags';
    protected $primaryKey     = 'id';
    protected $returnType     = Tag::class;
    protected $useSoftDeletes = false;
    protected $protectFields  = true;
    protected $allowedFields  = ['name', 'slug'];
    protected $useTimestamps  = true;

    /**
     * Create many tags.
     */
    public function createTags(Collection $tags, int $id, string $type): void
    {
        foreach ($tags->items() as $tag) {
            $this->createTag($tag, $id, $type);
        }
    }

    /**
     * Update many tags.
     */
    public function updateTags(Collection $tags, int $id, string $type): void
    {
        $this->db->table('taggable')
            ->where('taggable_id', $id)
            ->where('taggable_type', $type)
            ->delete();

        $this->createTags($tags, $id, $type);
        $this->cleanupTags();
    }

    /**
     * Create tag.
     *
     * @throws ReflectionException
     */
    public function createTag(Tag $tag, int $id, string $type): int
    {
        $data = [
            'tag_id'        => $this->findOrCreateId($tag),
            'taggable_id'   => $id,
            'taggable_type' => $type,
        ];

        $this->db->table('taggable')->insert($data);

        return $data['tag_id'];
    }

    /**
     * Cleanup tags which are no longer used.
     */
    public function cleanupTags(): bool|string
    {
        return $this->db
            ->table('tags')
            ->whereNotIn(
                'id',
                static fn (BaseBuilder $builder) => $builder->distinct()->select('tag_id')->from('taggable')
            )
            ->delete();
    }

    /**
     * Find or create tag and return tag ID.
     *
     * @throws ReflectionException
     */
    public function findOrCreateId(Tag $tag): int
    {
        $tagId = $this->where([
            'slug' => $tag->slug,
        ])->first()?->id;

        if (! $tagId) {
            $tagId = $this->insert($tag);
        }

        return $tagId;
    }

    /**
     * Find tags by name.
     */
    public function findByNames(array $names): array
    {
        return $this->whereIn('name', $names)->findAll();
    }

    /**
     * Get tags for one item.
     */
    public function getById(int $id, string $type): array
    {
        $tagIds = $this->db->table('taggable')
            ->select('tag_id')
            ->where('taggable_id', $id)
            ->where('taggable_type', $type)
            ->get()
            ->getResultArray();

        if (empty($tagIds)) {
            return [];
        }

        $tagIds = array_map('intval', array_column($tagIds, 'tag_id'));

        return $this->find($tagIds);
    }

    /**
     * Get tags for many items.
     */
    public function getByIds(array $ids, string $type): array
    {
        $tagIds = $this->db->table('taggable')
            ->select('tag_id, taggable_id')
            ->whereIn('taggable_id', $ids)
            ->where('taggable_type', $type)
            ->get()
            ->getResultArray();

        if (empty($tagIds)) {
            return [];
        }

        $taggableToTags = [];

        foreach ($tagIds as $tag) {
            $taggableToTags[$tag['taggable_id']][] = $tag['tag_id'];
        }
        $tagIds = array_map('intval', array_column($tagIds, 'tag_id'));

        $tags   = $this->find($tagIds);
        $tagIds = array_column($tags, 'id');
        $tags   = array_combine($tagIds, $tags);

        $results = [];

        foreach ($taggableToTags as $taggableId => $tagIds) {
            foreach ($tagIds as $tagId) {
                if (isset($tags[$tagId])) {
                    $results[$taggableId][] = $tags[$tagId];
                }
            }
        }

        return $results;
    }
}
