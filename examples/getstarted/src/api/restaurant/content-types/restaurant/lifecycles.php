<?php

declare(strict_types=1);

use Strapi\Database\Lifecycles\Event;

/**
 * Lifecycle callbacks for the `Restaurant` model.
 */
return [
    'beforeCreate' => static function (Event $event): void {
        // echo 'beforeCreate';
    },
    'afterCreate' => static function (Event $event): void {
        // echo 'afterCreate';
    },
    'beforeUpdate' => static function (Event $event): void {
        // echo 'beforeUpdate';
    },
    'afterUpdate' => static function (Event $event): void {
        // echo 'afterUpdate';
    },
    'beforeDelete' => static function (Event $event): void {
        // echo 'beforeDelete';
    },
    'afterDelete' => static function (Event $event): void {
        // echo 'afterDelete';
    },
    'beforeFindMany' => static function (Event $event): void {
        // echo 'beforeFindMany';
    },
    'afterFindMany' => static function (Event $event): void {
        // echo 'afterFindMany';
    },
    'beforeFindOne' => static function (Event $event): void {
        // echo 'beforeFindOne';
    },
    'afterFindOne' => static function (Event $event): void {
        // echo 'afterFindOne';
    },
];
