<?php

namespace Flarum\Mentions\Api;

use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;

class PostResourceFields
{
    public static int $maxMentionedBy = 4;

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('mentionedByCount')
                ->countRelation('mentionedBy'),

            Schema\Relationship\ToMany::make('mentionedBy')
                ->type('posts')
                ->includable()
                ->constrain(fn (Builder $query) => $query->oldest('id')->limit(static::$maxMentionedBy)),
            Schema\Relationship\ToMany::make('mentionsPosts')
                ->type('posts'),
            Schema\Relationship\ToMany::make('mentionsUsers')
                ->type('users'),
            Schema\Relationship\ToMany::make('mentionsGroups')
                ->type('groups'),
        ];
    }
}