<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Controllers;

use Strapi\Core\Middlewares\Session;
use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Middlewares\Documentation as DocumentationMiddleware;
use Strapi\Plugin\Documentation\Services\Documentation as DocumentationService;
use Strapi\Plugin\Documentation\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\Yup as YupSchema;

/**
 * Port of server/src/controllers/documentation.ts.
 *
 * `_.template()` fills the `<%=name%>` placeholders of `public/*.html`; koa-static serves the
 * filled page from `src/extensions/documentation/public/` (`Cache-Control: max-age=0`). The login
 * page's `.error` text is set in the template (upstream: cheerio, which also re-serializes the
 * markup). `bcryptjs` is `password_hash()` / `password_verify()`.
 *
 * @phpstan-import-type Config from \Strapi\Plugin\Documentation\Types
 */
final class Documentation
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private static function validateSettings(mixed $body): mixed
    {
        return Validators::validateYupSchema(
            Yup::object()->shape([
                'restrictedAccess' => Yup::boolean(),
                'password' => Yup::string()
                    ->min(8)
                    ->matches('/[a-z]/', '${path} must contain at least one lowercase character')
                    ->matches('/[A-Z]/', '${path} must contain at least one uppercase character')
                    ->matches('/\d/', '${path} must contain at least one number')
                    ->when('restrictedAccess', static function (mixed $value, YupSchema $initSchema): YupSchema {
                        return !$value instanceof Undefined && (bool) $value ? $initSchema->required('password is required') : $initSchema;
                    }),
            ]),
        )($body);
    }

    public function getInfos(Context $ctx): void
    {
        try {
            $docService = $this->service();
            $docVersions = $docService->getDocumentationVersions();
            $documentationAccess = $docService->getDocumentationAccess();

            $ctx->send([
                'docVersions' => $docVersions,
                'currentVersion' => $docService->getDocumentationVersion(),
                'prefix' => '/documentation',
                'documentationAccess' => $documentationAccess,
            ]);
        } catch (\Throwable $err) {
            $this->strapi->log()->error($err->getMessage(), ['exception' => $err]);
            $ctx->badRequest();
        }
    }

    public function index(Context $ctx, callable $next): mixed
    {
        try {
            /*
             * We don't expose the specs using koa-static or something else due to security reasons.
             * That's why, we need to read the file localy and send the specs through it when we serve the Swagger UI.
             */
            $major = $ctx->param('major');
            $minor = $ctx->param('minor');
            $patch = $ctx->param('patch');
            $version = $major !== null && $major !== '' && $minor !== null && $minor !== '' && $patch !== null && $patch !== ''
                ? "{$major}.{$minor}.{$patch}"
                : $this->service()->getDocumentationVersion();

            // upstream: dist.extensions in production; the PHP port has no dist directory
            $extensionsDir = $this->strapi->dirs()->extensions;

            $openAPISpecsPath = "{$extensionsDir}/documentation/documentation/{$version}/full_documentation.json";

            try {
                $documentation = @file_get_contents($openAPISpecsPath);
                if ($documentation === false) {
                    throw new \RuntimeException("ENOENT: no such file or directory, open '{$openAPISpecsPath}'");
                }

                $layout = (string) file_get_contents(dirname(__DIR__) . '/public/index.html');

                $spec = json_decode($documentation, false, 512, JSON_THROW_ON_ERROR);

                $filledLayout = self::template($layout, [
                    'backendUrl' => $this->strapi->config()->get('server.url'),
                    'spec' => (string) json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION),
                ]);

                try {
                    $layoutPath = "{$extensionsDir}/documentation/public/index.html";
                    self::writeFile($layoutPath, $filledLayout);

                    // Serve the file (koa-static on the public folder).
                    DocumentationMiddleware::send($ctx, $layoutPath, 0);

                    return null;
                } catch (\Throwable $e) {
                    $this->strapi->log()->error($e->getMessage(), ['exception' => $e]);
                }
            } catch (\Throwable $e) {
                $this->strapi->log()->error($e->getMessage(), ['exception' => $e]);
            }
        } catch (\Throwable $e) {
            $this->strapi->log()->error($e->getMessage(), ['exception' => $e]);
        }

        return null;
    }

    public function loginView(Context $ctx, callable $next): mixed
    {
        $error = $ctx->query()['error'] ?? null;

        try {
            $layout = (string) file_get_contents(dirname(__DIR__) . '/public/login.html');

            $filledLayout = self::template($layout, [
                'actionUrl' => self::toJsString($this->strapi->config()->get('server.url')) . '/documentation/login',
            ]);

            // $('.error').text(_.isEmpty(error) ? '' : 'Wrong password...')
            $errorText = self::isEmpty($error) ? '' : 'Wrong password...';
            $html = (string) preg_replace_callback(
                '~(<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bclass\s*=\s*"(?:[^"]*\s)?error(?:\s[^"]*)?"[^>]*>)(.*?)(</\2>)~s',
                static fn (array $m): string => $m[1] . htmlspecialchars($errorText, ENT_QUOTES | ENT_HTML5) . $m[4],
                $filledLayout,
            );

            try {
                $layoutPath = "{$this->strapi->dirs()->extensions}/documentation/public/login.html";
                self::writeFile($layoutPath, $html);

                DocumentationMiddleware::send($ctx, $layoutPath, 0);

                return null;
            } catch (\Throwable $e) {
                $this->strapi->log()->error($e->getMessage(), ['exception' => $e]);
            }
        } catch (\Throwable $e) {
            $this->strapi->log()->error($e->getMessage(), ['exception' => $e]);
        }

        return null;
    }

    public function login(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $password = is_array($body) ? ($body['password'] ?? null) : null;

        $config = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'documentation', 'key' => 'config']);
        $hash = is_array($config) ? ($config['password'] ?? null) : null;

        if (!is_string($password) || !is_string($hash)) {
            // bcryptjs: "Illegal arguments: <typeof password>, <typeof hash>"
            throw new \InvalidArgumentException('Illegal arguments: ' . self::jsTypeOf($password) . ', ' . self::jsTypeOf($hash));
        }

        $isValid = password_verify($password, $hash);

        $querystring = '?error=password';

        if ($isValid && $ctx->state()->has(Session::STATE_KEY)) {
            $session = $ctx->state()->get(Session::STATE_KEY);
            $session = is_array($session) ? $session : [];
            $session['documentation'] = [
                'logged' => true,
            ];
            $ctx->state()->set(Session::STATE_KEY, $session);

            $querystring = '';
        }

        $ctx->redirect(self::toJsString($this->strapi->config()->get('server.url')) . "/documentation{$querystring}");
    }

    public function regenerateDoc(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $version = is_array($body) ? ($body['version'] ?? null) : null;

        $service = $this->service();

        $documentationVersions = array_map(static fn (array $el): string => $el['version'], $service->getDocumentationVersions());

        if (self::isEmpty($version)) {
            $ctx->badRequest('Please provide a version.');

            return;
        }

        if (!is_string($version) || !in_array($version, $documentationVersions, true)) {
            $ctx->badRequest('The version you are trying to generate does not exist.');

            return;
        }

        try {
            $this->strapi->reload()->setWatching(false);
            $service->generateFullDoc($version);
            $ctx->send(['ok' => true]);
        } finally {
            $this->strapi->reload()->setWatching(true);
        }
    }

    public function deleteDoc(Context $ctx): void
    {
        $version = $ctx->param('version');

        $service = $this->service();

        $documentationVersions = array_map(static fn (array $el): string => $el['version'], $service->getDocumentationVersions());

        if (self::isEmpty($version)) {
            $ctx->badRequest('Please provide a version.');

            return;
        }

        if (!in_array($version, $documentationVersions, true)) {
            $ctx->badRequest('The version you are trying to delete does not exist.');

            return;
        }

        try {
            $this->strapi->reload()->setWatching(false);
            $service->deleteDocumentation((string) $version);
            $ctx->send(['ok' => true]);
        } finally {
            $this->strapi->reload()->setWatching(true);
        }
    }

    public function updateSettings(Context $ctx): void
    {
        $pluginStore = $this->strapi->store()(['type' => 'plugin', 'name' => 'documentation']);

        $data = self::validateSettings($ctx->requestBody());
        $data = is_array($data) ? $data : [];

        /** @var Config $config */
        $config = [
            'restrictedAccess' => (bool) ($data['restrictedAccess'] ?? false),
        ];

        $password = $data['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $config['password'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        }

        $pluginStore->set(['key' => 'config', 'value' => $config]);

        $ctx->send(['ok' => true]);
    }

    // --- helpers -----------------------------------------------------------------------------

    private function service(): DocumentationService
    {
        return Utils::getService('documentation', $this->strapi);
    }

    /**
     * lodash `_.template(layout)(data)` for `<%= name %>` interpolations (`null` prints as '').
     *
     * @param array<string, mixed> $data
     */
    private static function template(string $layout, array $data): string
    {
        return (string) preg_replace_callback('/<%=([\s\S]+?)%>/', static function (array $m) use ($data): string {
            $value = $data[trim($m[1])] ?? null;

            return match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => '',
            };
        }, $layout);
    }

    /** `fs.ensureFile(file)` + `fs.writeFile(file, data)` */
    private static function writeFile(string $file, string $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("EACCES: permission denied, mkdir '{$dir}'");
        }
        if (@file_put_contents($file, $data) === false) {
            throw new \RuntimeException("EACCES: permission denied, open '{$file}'");
        }
    }

    /** lodash `_.isEmpty` */
    private static function isEmpty(mixed $value): bool
    {
        return match (true) {
            $value === null => true,
            is_string($value) => $value === '',
            is_array($value) => $value === [],
            $value instanceof \Countable => count($value) === 0,
            is_object($value) => get_object_vars($value) === [],
            default => true, // numbers and booleans have no own enumerable keys
        };
    }

    private static function toJsString(mixed $value): string
    {
        return $value === null ? 'undefined' : (is_scalar($value) ? (string) $value : '');
    }

    private static function jsTypeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            default => 'object',
        };
    }
}
