<?php

declare(strict_types=1);

namespace Strapi\Upload\Services\Extensions;

use Strapi\Core\Strapi;
use Strapi\Upload\Provider as UploadProvider;

/** Port of server/src/services/extensions/index.ts. */
final class Extensions
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function signFileUrlsOnDocumentService(): void
    {
        $provider = $this->strapi->plugin('upload')->provider;
        $isPrivate = $provider instanceof UploadProvider && $provider->isPrivate();

        // We only need to sign the file urls if the provider is private
        if (!$isPrivate) {
            return;
        }

        $strapi = $this->strapi;

        $strapi->documentService()->use(static function (array $ctx, callable $next) use ($strapi): mixed {
            $uid = (string) ($ctx['uid'] ?? '');
            $action = $ctx['action'] ?? null;

            // One presign per distinct richtext URL for this call, whichever entry it
            // appears in. Scoped to the call: a signed URL is only as fresh as the
            // request that produced it.
            $cache = Utils::createSignCache();

            // Never persist a signature: richtext / blocks embed the URL itself, so an
            // expiring one would be frozen in the row. `clone` is included because the
            // submitted data is merged over the source row, so it can carry signed
            // values too. The response is signed again below, so callers still get a
            // usable URL back.
            if (in_array($action, ['create', 'update', 'clone'], true) && !empty($ctx['params']['data'])) {
                $ctx['params']['data'] = Utils::unsignEntityMedia($strapi, $ctx['params']['data'], $uid, $cache);
            }

            $result = $next($ctx);

            if ($action === 'findMany') {
                // Shape: [ entry ]
                return is_array($result)
                    ? array_map(static fn (mixed $entry): mixed => Utils::signEntityMedia($strapi, $entry, $uid, $cache), $result)
                    : $result;
            }

            if (in_array($action, ['findFirst', 'findOne', 'create', 'update'], true)) {
                // Shape: entry
                return Utils::signEntityMedia($strapi, $result, $uid, $cache);
            }

            if (in_array($action, ['delete', 'clone', 'publish', 'unpublish', 'discardDraft'], true)) {
                // Shape: { entries: [ entry ] }
                if (is_array($result) && is_array($result['entries'] ?? null)) {
                    $result['entries'] = array_map(static fn (mixed $entry): mixed => Utils::signEntityMedia($strapi, $entry, $uid, $cache), $result['entries']);
                }

                return $result;
            }

            return $result;
        });
    }
}
