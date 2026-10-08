<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\ConsoleInquirer;
use Strapi\Generators\Inquirer;
use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\BootstrapApiPrompts;
use Strapi\Generators\Plops\Prompts\CtNamesPrompts;
use Strapi\Generators\Plops\Prompts\GetAttributesPrompts;
use Strapi\Generators\Plops\Prompts\GetDestinationPrompts;
use Strapi\Generators\Plops\Prompts\GetFolderPrompts;
use Strapi\Generators\Plops\Prompts\KindPrompts;
use Strapi\Generators\Plops\Utils\ContentStructure;
use Strapi\Generators\Plops\Utils\Files;
use Strapi\Generators\Plops\Utils\GetFilePath;
use Strapi\Generators\Plops\Utils\PluginIndexActions;
use Strapi\Generators\Template;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of src/plops/content-type.ts: `strapi generate content-type`.
 *
 * Non-interactive runs may pass the attributes as `--attributes=name:type,...` (an `enumeration`
 * takes its values as `status:enumeration:draft|published`, a `media` `cover:media:single` or
 * `gallery:media:multiple`); upstream's function prompts cannot be bypassed.
 */
final class ContentType
{
    public function __invoke(Plop $plop): void
    {
        // Model generator
        $plop->setGenerator('content-type', [
            'description' => 'Generate a content type for an API',
            'prompts' => static function (Inquirer $inquirer) use ($plop): array {
                $config = $inquirer->prompt([...CtNamesPrompts::questions(), ...KindPrompts::questions()]);
                $attributes = $inquirer instanceof ConsoleInquirer && $inquirer->hasPreset('attributes')
                    ? self::parseAttributes($inquirer->preset('attributes'))
                    : GetAttributesPrompts::getAttributesPrompts($inquirer);

                $api = $inquirer->prompt([
                    ...GetDestinationPrompts::getDestinationPrompts('model', $plop->getDestBasePath()),
                    [
                        'when' => static fn (array $answers): bool => ($answers['destination'] ?? null) === 'new',
                        'type' => 'input',
                        'name' => 'id',
                        'default' => $config['singularName'] ?? null,
                        'message' => 'Name of the new API?',
                        'validate' => static function (mixed $input) use ($plop): bool|string {
                            if (!is_string($input) || !Strings::isKebabCase($input)) {
                                return 'Value must be in kebab-case';
                            }

                            $apiPath = $plop->getDestBasePath() . '/api';

                            if (!file_exists($apiPath)) {
                                return true;
                            }

                            if (in_array($input, GetDestinationPrompts::directories($apiPath), true)) {
                                throw new \RuntimeException('This name is already taken.');
                            }

                            return true;
                        },
                    ],
                    ...BootstrapApiPrompts::questions(),
                ]);

                $folder = GetFolderPrompts::getFolderPrompts($inquirer, $plop, [
                    'destination' => is_string($api['destination'] ?? null) ? $api['destination'] : null,
                    'kind' => (string) ($config['kind'] ?? 'collectionType'),
                ]);

                return [
                    ...$config,
                    ...$api,
                    ...$folder,
                    'attributes' => $attributes,
                ];
            },
            'actions' => static function (array $answers) use ($plop): array {
                if ($answers === []) {
                    return [];
                }

                $attributes = [];
                foreach (is_array($answers['attributes'] ?? null) ? $answers['attributes'] : [] as $answer) {
                    $val = ['type' => $answer['attributeType']];

                    if ($answer['attributeType'] === 'enumeration') {
                        $val['enum'] = array_map('trim', explode(',', (string) ($answer['enum'] ?? '')));
                    }

                    if ($answer['attributeType'] === 'media') {
                        $val['allowedTypes'] = ['images', 'files', 'videos', 'audios'];
                        if (array_key_exists('multiple', $answer)) {
                            $val['multiple'] = $answer['multiple'];
                        }
                    }

                    $attributes[$answer['attributeName']] = $val;
                }

                $destination = is_string($answers['destination'] ?? null) ? $answers['destination'] : null;
                $filePath = GetFilePath::getFilePath($destination);

                $singularName = (string) ($answers['singularName'] ?? '');

                $schemaActionPath = "{$filePath}/content-types/{{ singularName }}/schema.json";

                $uid = null;
                if ($destination === 'new') {
                    $uid = "api::{$answers['id']}.{$singularName}";
                } elseif (!empty($answers['api'])) {
                    $uid = "api::{$answers['api']}.{$singularName}";
                } elseif (!empty($answers['plugin'])) {
                    $uid = "plugin::{$answers['plugin']}.{$singularName}";
                }

                $baseActions = [
                    [
                        'type' => 'add',
                        'path' => $schemaActionPath,
                        'templateFile' => 'templates/php/content-type.schema.json.tpl',
                        'data' => [
                            'collectionName' => Strings::slugify((string) ($answers['pluralName'] ?? ''), ['separator' => '_']),
                        ],
                    ],
                ];

                if ($attributes !== []) {
                    $baseActions[] = [
                        'type' => 'modify',
                        'path' => $schemaActionPath,
                        'transform' => static function (string $template) use ($attributes): string {
                            $parsedTemplate = json_decode($template, false, 512, JSON_THROW_ON_ERROR);
                            $parsedTemplate->attributes = $attributes;

                            return Files::stringify($parsedTemplate);
                        },
                    ];
                }

                if (!empty($answers['plugin'])) {
                    // Append the new content type to the content-types/index.php file
                    $baseActions = [
                        ...$baseActions,
                        ...PluginIndexActions::actions($plop, $filePath, $answers, ['content-types'], $singularName),
                    ];
                }

                if (($answers['bootstrapApi'] ?? false) === true) {
                    $baseActions[] = [
                        'type' => 'add',
                        'path' => "{$filePath}/controllers/{{ singularName }}.php",
                        'templateFile' => 'templates/php/core-controller.php.tpl',
                        'data' => ['uid' => $uid],
                    ];
                    $baseActions[] = [
                        'type' => 'add',
                        'path' => "{$filePath}/services/{{ singularName }}.php",
                        'templateFile' => 'templates/php/core-service.php.tpl',
                        'data' => ['uid' => $uid],
                    ];
                    $baseActions[] = [
                        'type' => 'add',
                        'path' => "{$filePath}/routes/" . (!empty($answers['plugin']) ? 'content-api/' : '') . '{{ singularName }}.php',
                        'templateFile' => 'templates/php/core-router.php.tpl',
                        'data' => ['uid' => $uid],
                    ];

                    if (!empty($answers['plugin'])) {
                        $baseActions = [
                            ...$baseActions,
                            ...PluginIndexActions::actions($plop, $filePath, $answers, ['controllers', 'services', 'routes'], $singularName, 'core'),
                        ];
                    }
                }

                $folder = $answers['folder'] ?? null;
                if (is_array($folder) && $folder !== []) {
                    if ($uid === null) {
                        throw new \RuntimeException('Cannot assign a folder: could not determine the content type uid from the given destination.');
                    }

                    $contentTypeAlreadyExists = file_exists($plop->getDestBasePath() . '/' . Template::render($schemaActionPath, $answers));

                    if ($contentTypeAlreadyExists) {
                        throw new \RuntimeException("Content type \"{$uid}\" already exists — refusing to reassign its folder from the generator.");
                    }

                    $assignedUid = $uid;

                    /** @var array{targetGroupId: string}|array{newFolderName: string} $folder */
                    $commitFolderAssignment = ContentStructure::planContentTypeToFolder([
                        'destBasePath' => $plop->getDestBasePath(),
                        'folder' => $folder,
                        'kind' => (string) ($answers['kind'] ?? 'collectionType'),
                        'uid' => $assignedUid,
                    ]);

                    $baseActions[] = static function () use ($commitFolderAssignment, $assignedUid): string {
                        $commitFolderAssignment();

                        return "assigned \"{$assignedUid}\" to folder";
                    };
                }

                return $baseActions;
            },
        ]);
    }

    /**
     * `--attributes=title:string,status:enumeration:draft|published,cover:media:single`
     *
     * @return list<array{attributeName: string, attributeType: string, enum?: string, multiple?: bool}>
     */
    public static function parseAttributes(mixed $value): array
    {
        if (is_array($value)) {
            $attributes = [];
            foreach ($value as $attribute) {
                if (is_array($attribute) && is_string($attribute['attributeName'] ?? null) && is_string($attribute['attributeType'] ?? null)) {
                    $attributes[] = ['attributeName' => $attribute['attributeName'], 'attributeType' => $attribute['attributeType']]
                        + array_filter(['enum' => is_string($attribute['enum'] ?? null) ? $attribute['enum'] : null], static fn (mixed $v): bool => $v !== null)
                        + (is_bool($attribute['multiple'] ?? null) ? ['multiple' => $attribute['multiple']] : []);
                }
            }

            return $attributes;
        }

        $attributes = [];
        foreach (array_filter(array_map('trim', explode(',', is_scalar($value) ? (string) $value : ''))) as $definition) {
            $parts = explode(':', $definition, 3);
            $name = $parts[0];
            $type = $parts[1] ?? 'string';
            $option = $parts[2] ?? null;

            if (!in_array($type, GetAttributesPrompts::DEFAULT_TYPES, true)) {
                throw new \RuntimeException("Invalid attribute type \"{$type}\" for \"{$name}\": expected one of " . implode(', ', GetAttributesPrompts::DEFAULT_TYPES));
            }

            $attribute = ['attributeName' => $name, 'attributeType' => $type];
            if ($type === 'enumeration') {
                $attribute['enum'] = str_replace('|', ',', (string) $option);
            }
            if ($type === 'media') {
                $attribute['multiple'] = $option === 'multiple';
            }
            $attributes[] = $attribute;
        }

        return $attributes;
    }
}
