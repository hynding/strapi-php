<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Routes\ContentApi;

use Strapi\Core\Strapi;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodString;
use Strapi\Utils\Zod\ZodType;
use Strapi\Utils\Zod\ZodUnion;

/**
 * Port of server/src/routes/content-api/validation.js.
 *
 * Upstream extends `@strapi/utils`' `AbstractRouteValidator` for the common query parameters
 * (`fields`, `populate`, `sort`, `pagination`, `filters`); that class is not ported, so those
 * schemas are permissive here, as in the upload package (core only reads the declared query
 * keys, for strictParams). Upstream's getters are methods.
 */
final class UsersPermissionsRouteValidator
{
    public function __construct(protected readonly ?Strapi $strapi = null)
    {
    }

    /**
     * A single role relation entry, referenced by numeric `id` (legacy) or by
     * `documentId` (the v5 default), but must carry at least one of them. The
     * detailed rules (min one role on create, cannot remove the last role on
     * update) stay in the Yup controller validator.
     */
    private static function roleRelationEntry(): ZodType
    {
        return z::object([
            'id' => z::union([z::number(), z::string()])->optional(),
            'documentId' => z::string()->optional(),
        ])->refine(
            static fn (mixed $entry): bool => is_array($entry) && (($entry['id'] ?? null) !== null || ($entry['documentId'] ?? null) !== null),
            ['message' => 'Relation entry must include an id or documentId'],
        );
    }

    /**
     * The `role` relation input: shorthand scalar (numeric id or documentId) or the
     * longhand connect/disconnect object, matching every other v5 relation. The
     * object form must carry at least one of connect/disconnect (an empty object is
     * a no-op and almost certainly a mistake).
     */
    private static function roleRelationInput(): ZodUnion
    {
        return z::union([
            z::number(),
            z::string(),
            z::object([
                'connect' => z::array(self::roleRelationEntry())->optional(),
                'disconnect' => z::array(self::roleRelationEntry())->optional(),
            ])->refine(
                static fn (mixed $input): bool => is_array($input) && (($input['connect'] ?? null) !== null || ($input['disconnect'] ?? null) !== null),
                ['message' => 'Relation input must include connect or disconnect'],
            ),
        ]);
    }

    public function userSchema(): ZodObject
    {
        return z::object([
            'id' => z::number(),
            'documentId' => z::string(),
            'username' => z::string(),
            'email' => z::string(),
            'provider' => z::string(),
            'confirmed' => z::boolean(),
            'blocked' => z::boolean(),
            'role' => z::union([
                z::number(),
                z::object([
                    'id' => z::number(),
                    'name' => z::string(),
                    'description' => z::string()->nullable(),
                    'type' => z::string(),
                    'createdAt' => z::string(),
                    'updatedAt' => z::string(),
                ]),
            ])->optional(),
            'createdAt' => z::string(),
            'updatedAt' => z::string(),
            'publishedAt' => z::string(),
        ]);
    }

    private static function permissionsTree(): ZodType
    {
        return z::record(
            z::string(), // plugin name
            z::object([
                'controllers' => z::record(
                    z::string(), // controller name
                    z::record(
                        z::string(), // action name
                        z::object([
                            'enabled' => z::boolean(),
                            'policy' => z::string(),
                        ]),
                    ),
                ),
            ]),
        );
    }

    public function roleSchema(): ZodObject
    {
        return z::object([
            'id' => z::number(),
            'documentId' => z::string(),
            'name' => z::string(),
            'description' => z::string()->nullable(),
            'type' => z::string(),
            'createdAt' => z::string(),
            'updatedAt' => z::string(),
            'publishedAt' => z::string(),
            'nb_users' => z::number()->optional(),
            'permissions' => self::permissionsTree()->optional(),
            'users' => z::array(z::unknown())->optional(),
        ]);
    }

    public function permissionSchema(): ZodObject
    {
        return z::object([
            'id' => z::number(),
            'action' => z::string(),
            'role' => z::object([
                'id' => z::number(),
                'name' => z::string(),
                'description' => z::string()->nullable(),
                'type' => z::string(),
            ]),
            'createdAt' => z::string(),
            'updatedAt' => z::string(),
        ]);
    }

    public function authResponseSchema(): ZodObject
    {
        return z::object([
            'jwt' => z::string(),
            'refreshToken' => z::string()->optional(),
            'user' => $this->userSchema(),
        ]);
    }

    public function authResponseWithoutJwtSchema(): ZodObject
    {
        return z::object([
            'user' => $this->userSchema(),
        ]);
    }

    public function authRegisterResponseSchema(): ZodUnion
    {
        return z::union([$this->authResponseSchema(), $this->authResponseWithoutJwtSchema()]);
    }

    public function forgotPasswordResponseSchema(): ZodObject
    {
        return z::object([
            'ok' => z::boolean(),
        ]);
    }

    public function sendEmailConfirmationResponseSchema(): ZodObject
    {
        return z::object([
            'email' => z::string(),
            'sent' => z::boolean(),
        ]);
    }

    public function rolesResponseSchema(): ZodObject
    {
        return z::object([
            'roles' => z::array($this->roleSchema()),
        ]);
    }

    public function roleResponseSchema(): ZodObject
    {
        return z::object([
            'role' => $this->roleSchema(),
        ]);
    }

    public function roleSuccessResponseSchema(): ZodObject
    {
        return z::object([
            'ok' => z::boolean(),
        ]);
    }

    public function permissionsResponseSchema(): ZodObject
    {
        return z::object([
            'permissions' => self::permissionsTree(),
        ]);
    }

    public function loginBodySchema(): ZodObject
    {
        return z::object([
            'identifier' => z::string(),
            'password' => z::string(),
        ]);
    }

    public function registerBodySchema(): ZodObject
    {
        return z::object([
            'username' => z::string(),
            'email' => z::email(),
            'password' => z::string(),
        ]);
    }

    public function forgotPasswordBodySchema(): ZodObject
    {
        return z::object([
            'email' => z::email(),
        ]);
    }

    public function resetPasswordBodySchema(): ZodObject
    {
        return z::object([
            'code' => z::string(),
            'password' => z::string(),
            'passwordConfirmation' => z::string(),
        ]);
    }

    public function changePasswordBodySchema(): ZodObject
    {
        return z::object([
            'currentPassword' => z::string(),
            'password' => z::string(),
            'passwordConfirmation' => z::string(),
        ]);
    }

    public function sendEmailConfirmationBodySchema(): ZodObject
    {
        return z::object([
            'email' => z::email(),
        ]);
    }

    public function createUserBodySchema(): ZodObject
    {
        return z::object([
            'username' => z::string(),
            'email' => z::email(),
            'password' => z::string(),
            'role' => self::roleRelationInput()->optional(),
        ]);
    }

    public function updateUserBodySchema(): ZodObject
    {
        return z::object([
            'username' => z::string()->optional(),
            'email' => z::email()->optional(),
            'password' => z::string()->optional(),
            'role' => self::roleRelationInput()->optional(),
        ]);
    }

    public function createRoleBodySchema(): ZodObject
    {
        return z::object([
            'name' => z::string(),
            'description' => z::string()->optional(),
            'type' => z::string(),
            'permissions' => z::record(z::string(), z::unknown())->optional(),
        ]);
    }

    public function updateRoleBodySchema(): ZodObject
    {
        return z::object([
            'name' => z::string()->optional(),
            'description' => z::string()->optional(),
            'type' => z::string()->optional(),
            'permissions' => z::record(z::string(), z::unknown())->optional(),
        ]);
    }

    public function userIdParam(): ZodString
    {
        return z::string();
    }

    public function roleIdParam(): ZodString
    {
        return z::string();
    }

    public function providerParam(): ZodString
    {
        return z::string();
    }

    // AbstractRouteValidator's query parameter schemas

    public function queryFields(): ZodType
    {
        return z::any();
    }

    public function queryPopulate(): ZodType
    {
        return z::any();
    }

    public function querySort(): ZodType
    {
        return z::any();
    }

    public function pagination(): ZodType
    {
        return z::any();
    }

    public function filters(): ZodType
    {
        return z::any();
    }
}
