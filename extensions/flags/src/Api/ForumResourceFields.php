<?php

namespace Flarum\Flags\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Flags\Flag;
use Flarum\Settings\SettingsRepositoryInterface;

class ForumResourceFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canViewFlags')
                ->get(function (object $model, Context $context) {
                    return $context->getActor()->hasPermissionLike('discussion.viewFlags');
                }),
            Schema\Integer::make('flagCount')
                ->visible(fn (object $model, Context $context) => $context->getActor()->hasPermissionLike('discussion.viewFlags'))
                ->get(function (object $model, Context $context) {
                    return Flag::whereVisibleTo($context->getActor())->distinct()->count('flags.post_id');
                }),
        ];
    }
}
