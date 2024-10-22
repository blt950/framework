<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'dialog_message_mentions_group',
    function (Blueprint $table) {
        $table->unsignedInteger('dialog_message_id');
        $table->unsignedInteger('mentions_group_id');
        $table->dateTime('created_at')->nullable()->useCurrent();

        $table->primary(['dialog_message_id', 'mentions_group_id']);
        $table->foreign('dialog_message_id')->references('id')->on('dialog_messages')->cascadeOnDelete();
        $table->foreign('mentions_group_id')->references('id')->on('groups')->cascadeOnDelete();
    }
);