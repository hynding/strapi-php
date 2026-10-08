<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql;

use Strapi\Core\Strapi;

/** Port of server/src/graphql/utils.js */
final class Utils
{
    /**
     * Throws an ApolloError if context body contains a bad request
     *
     * @param mixed $contextBody - body of the context object given to the resolver
     * @throws BadRequestException if the body is a bad request
     */
    public static function checkBadRequest(mixed $contextBody): void
    {
        $statusCode = is_array($contextBody) && array_key_exists('statusCode', $contextBody) ? $contextBody['statusCode'] : 200;

        if ($statusCode !== 200) {
            $errorMessage = is_array($contextBody) && array_key_exists('error', $contextBody) ? $contextBody['error'] : 'Bad Request';

            throw new BadRequestException(is_string($errorMessage) ? $errorMessage : 'Bad Request', $statusCode ?: 400, $contextBody);
        }
    }

    /**
     * `strapi.plugin('users-permissions').controller(name)[action]` (PHP-port addition: the
     * resolvers call the controllers' actions by name, as upstream does on plain objects).
     */
    public static function controllerAction(Strapi $strapi, string $controller, string $action): callable
    {
        $fn = [$strapi->plugin('users-permissions')->controller($controller), $action];
        if (!is_callable($fn)) {
            throw new \RuntimeException("The users-permissions {$controller} controller has no {$action} action");
        }

        return $fn;
    }
}
