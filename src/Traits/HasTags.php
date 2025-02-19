<?php

namespace Michalsn\CodeIgniterTags\Traits;

use CodeIgniter\Database\BaseBuilder;
use Michalsn\CodeIgniterTags\Enums\ScopeTypes;
use Michalsn\CodeIgniterTags\Models\TagModel;
use Michalsn\CodeIgniterTags\Scopes\TagScope;
use Myth\Collection\Collection;

trait HasTags
{
    protected bool $includeTags = false;
    protected Collection $scopeTags;
    protected Collection $tags;
    protected string $tagType;

    /**
     * Set up model events and initialize tags related stuff.
     */
    protected function initTags(): void
    {
        $this->beforeInsert[]  = 'tagsBeforeInsert';
        $this->afterInsert[]   = 'tagsAfterInsert';
        $this->beforeUpdate[]  = 'tagsBeforeUpdate';
        $this->afterUpdate[]   = 'tagsAfterUpdate';
        $this->beforeFind[]    = 'tagsBeforeFind';
        $this->afterFind[]     = 'tagsAfterFind';
        $this->allowedFields[] = 'tags';

        helper('inflector');

        $this->scopeTags = new Collection([]);
        $this->tags      = new Collection([]);
        $this->tagType   = plural($this->table);
    }

    /**
     * Include tags to the result.
     */
    public function withTags(): static
    {
        $this->includeTags = true;

        return $this;
    }

    /**
     * The result must have all tags assigned.
     */
    public function withAllTags(array $tags): static
    {
        $this->scopeTags->push(new TagScope($tags, $this->tagType, ScopeTypes::All));

        $this->includeTags = true;

        return $this;
    }

    /**
     * The result must have any of these tags assigned.
     */
    public function withAnyTags(array $tags): static
    {
        $this->scopeTags->push(new TagScope($tags, $this->tagType, ScopeTypes::Any));

        $this->includeTags = true;

        return $this;
    }

    /**
     * Set tags from eventData array.
     */
    protected function setTags(array|string|Collection $tags): static
    {
        $this->tags = convert_to_tags($tags)->unique('name')->values();

        $this->withTags();

        return $this;
    }

    /**
     * Before insert event.
     */
    protected function tagsBeforeInsert(array $eventData): array
    {
        if (array_key_exists('tags', $eventData['data'])) {
            $this->setTags($eventData['data']['tags']);
            unset($eventData['data']['tags']);
        }

        return $eventData;
    }

    /**
     * After insert event.
     */
    protected function tagsAfterInsert(array $eventData): void
    {
        if ($this->includeTags && $eventData['result']) {
            model(TagModel::class)->createTags($this->tags, $eventData['id'], $this->tagType);
            $this->tags = new Collection([]);
        }
    }

    /**
     * Before update event.
     */
    protected function tagsBeforeUpdate(array $eventData): array
    {
        if (array_key_exists('tags', $eventData['data'])) {
            $this->setTags($eventData['data']['tags']);
            unset($eventData['data']['tags']);
        }

        return $eventData;
    }

    /**
     * After update event.
     */
    protected function tagsAfterUpdate(array $eventData): void
    {
        if ($this->includeTags && $eventData['result']) {
            foreach ($eventData['id'] as $id) {
                model(TagModel::class)->updateTags($this->tags, $id, $this->tagType);
            }
            $this->tags = new Collection([]);
        }
    }

    /**
     * Before find event.
     */
    protected function tagsBeforeFind(array $eventData): array
    {
        if ($this->scopeTags->count() === 0) {
            return $eventData;
        }

        $builder = $this->builder();

        $builder->groupStart();

        $this->scopeTags->map(function ($tagScope) use ($builder) {
            if ($tagIds = $tagScope->getTagsIds()) {
                $builder
                    ->orGroupStart()
                    ->whereIn(
                        $this->primaryKey,
                        static fn (BaseBuilder $builder) => $tagScope->getQuery($builder, $tagIds)
                    )
                    ->groupEnd();
            }
        });

        $builder->groupEnd();

        $this->scopeTags = new Collection([]);

        return $eventData;
    }

    /**
     * After find event.
     */
    protected function tagsAfterFind(array $eventData): array
    {
        if (! $this->includeTags || empty($eventData['data'])) {
            return $eventData;
        }

        $tagModel = model(TagModel::class);

        if ($eventData['singleton']) {
            if ($this->tempReturnType === 'array') {
                $eventData['data']['tags'] = new Collection($tagModel->getById($eventData['data'][$this->primaryKey], $this->tagType));
            } else {
                $eventData['data']->tags = new Collection($tagModel->getById($eventData['data']->{$this->primaryKey}, $this->tagType));
            }
        } else {
            $keys = array_map('intval', array_column($eventData['data'], $this->primaryKey));
            $tags = $tagModel->getByIds($keys, $this->tagType);

            foreach ($eventData['data'] as &$data) {
                if ($this->tempReturnType === 'array') {
                    $data['tags'] = new Collection($tags[$data[$this->primaryKey]] ?? []);
                } else {
                    $data->tags = new Collection($tags[$data->{$this->primaryKey}] ?? []);
                }
            }
        }

        $this->includeTags = false;

        return $eventData;
    }
}
