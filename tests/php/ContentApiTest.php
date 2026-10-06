<?php

declare(strict_types=1);

namespace Strapi\Tests;

use PHPUnit\Framework\Attributes\Depends;

/**
 * The content API of examples/getstarted through `server.handle()`: collection types, single
 * types, filters/sort/pagination/populate, draft & publish, relations, components, dynamic zones,
 * validation errors, policies, CORS and the security headers.
 */
final class ContentApiTest extends AppTestCase
{
    public function testEmptyCollectionEnvelope(): void
    {
        [$response, $body] = self::call('GET', '/api/articles');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(['data' => [], 'meta' => ['pagination' => ['page' => 1, 'pageSize' => 25, 'pageCount' => 0, 'total' => 0]]], $body);
    }

    public function testCreateReturnsFlatEntityWithDocumentId(): string
    {
        [$response, $body] = self::call('POST', '/api/articles', ['data' => ['title' => 'Hello world', 'authorName' => 'Bob']]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Hello world', $body['data']['title']);
        self::assertSame('Bob', $body['data']['authorName']);
        self::assertIsInt($body['data']['id']);
        self::assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $body['data']['documentId']);
        self::assertArrayNotHasKey('attributes', $body['data'], 'Strapi 5 entities are flat');
        self::assertNotNull($body['data']['publishedAt'], 'the content API creates published entries by default');
        self::assertStringEndsWith(',"meta":{}}', (string) $response->getBody(), 'an empty meta is an object');

        return $body['data']['documentId'];
    }

    #[Depends('testCreateReturnsFlatEntityWithDocumentId')]
    public function testFindOneAndListAfterCreate(string $documentId): string
    {
        [$response, $body] = self::call('GET', "/api/articles/{$documentId}");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($documentId, $body['data']['documentId']);
        self::assertSame('Hello world', $body['data']['title']);

        [, $list] = self::call('GET', '/api/articles');
        self::assertSame(1, $list['meta']['pagination']['total']);
        self::assertSame($documentId, $list['data'][0]['documentId']);

        return $documentId;
    }

    #[Depends('testFindOneAndListAfterCreate')]
    public function testFiltersSortPaginationAndFields(string $documentId): void
    {
        self::call('POST', '/api/articles', ['data' => ['title' => 'Another article', 'authorName' => 'Alice']]);
        self::call('POST', '/api/articles', ['data' => ['title' => 'Zebra article', 'authorName' => 'Zed']]);

        [, $body] = self::call('GET', '/api/articles?filters[title][$contains]=article&sort=title:desc&pagination[pageSize]=1&pagination[page]=1&fields[0]=title');
        self::assertSame(['page' => 1, 'pageSize' => 1, 'pageCount' => 2, 'total' => 2], $body['meta']['pagination']);
        self::assertCount(1, $body['data']);
        self::assertSame('Zebra article', $body['data'][0]['title']);
        self::assertSame(['id', 'documentId', 'title'], array_keys($body['data'][0]));

        [, $page2] = self::call('GET', '/api/articles?filters[title][$contains]=article&sort=title:desc&pagination[pageSize]=1&pagination[page]=2');
        self::assertSame('Another article', $page2['data'][0]['title']);

        [, $eq] = self::call('GET', '/api/articles?filters[authorName][$eq]=Bob');
        self::assertSame(1, $eq['meta']['pagination']['total']);
        self::assertSame($documentId, $eq['data'][0]['documentId']);

        [, $startLimit] = self::call('GET', '/api/articles?pagination[start]=1&pagination[limit]=1&sort=title');
        self::assertSame(['start' => 1, 'limit' => 1, 'total' => 3], $startLimit['meta']['pagination']);
        self::assertSame('Hello world', $startLimit['data'][0]['title']);
    }

    #[Depends('testFindOneAndListAfterCreate')]
    public function testUpdate(string $documentId): string
    {
        [$response, $body] = self::call('PUT', "/api/articles/{$documentId}", ['data' => ['title' => 'Updated title']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Updated title', $body['data']['title']);
        self::assertSame('Bob', $body['data']['authorName'], 'untouched attributes are kept');
        self::assertSame($documentId, $body['data']['documentId']);

        [, $draft] = self::call('GET', "/api/articles/{$documentId}?status=draft");
        self::assertSame('Updated title', $draft['data']['title']);
        self::assertNull($draft['data']['publishedAt']);

        return $documentId;
    }

    #[Depends('testUpdate')]
    public function testRelationConnectByDocumentIdAndPopulate(string $documentId): string
    {
        [$response, $category] = self::call('POST', '/api/categories', ['data' => ['name' => 'Tech']]);
        self::assertSame(201, $response->getStatusCode());
        $categoryId = $category['data']['documentId'];

        [$response] = self::call('PUT', "/api/articles/{$documentId}", ['data' => ['categories' => ['connect' => [$categoryId]]]]);
        self::assertSame(200, $response->getStatusCode());

        [, $populated] = self::call('GET', "/api/articles/{$documentId}?populate=categories");
        self::assertCount(1, $populated['data']['categories']);
        self::assertSame($categoryId, $populated['data']['categories'][0]['documentId']);
        self::assertSame('Tech', $populated['data']['categories'][0]['name']);

        [, $fields] = self::call('GET', "/api/articles/{$documentId}?populate[categories][fields][0]=name");
        self::assertSame(['id', 'documentId', 'name'], array_keys($fields['data']['categories'][0]));

        [, $inverse] = self::call('GET', "/api/categories/{$categoryId}?populate=articles");
        self::assertSame($documentId, $inverse['data']['articles'][0]['documentId']);

        [, $bare] = self::call('GET', "/api/articles/{$documentId}");
        self::assertArrayNotHasKey('categories', $bare['data'], 'relations are not populated by default');

        [$response] = self::call('PUT', "/api/articles/{$documentId}", ['data' => ['categories' => [$categoryId]]]);
        self::assertSame(200, $response->getStatusCode());
        [, $populated] = self::call('GET', "/api/articles/{$documentId}?populate=categories");
        self::assertCount(1, $populated['data']['categories']);

        [$response] = self::call('PUT', "/api/articles/{$documentId}", ['data' => ['categories' => ['disconnect' => [$categoryId]]]]);
        [, $populated] = self::call('GET', "/api/articles/{$documentId}?populate=categories");
        self::assertSame([], $populated['data']['categories']);

        return $documentId;
    }

    public function testRelationConnectUnknownDocumentIdIsAValidationError(): void
    {
        [$response, $body] = self::call('POST', '/api/articles', ['data' => ['title' => 'x', 'categories' => ['connect' => ['doesnotexist']]]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('ValidationError', $body['error']['name']);
    }

    #[Depends('testRelationConnectByDocumentIdAndPopulate')]
    public function testDraftAndPublish(string $documentId): void
    {
        // a draft-only entry does not appear in the published list
        [$response, $draft] = self::call('POST', '/api/articles?status=draft', ['data' => ['title' => 'Draft only', 'authorName' => 'D']]);
        self::assertSame(201, $response->getStatusCode());
        self::assertNull($draft['data']['publishedAt']);
        $draftId = $draft['data']['documentId'];

        [$response] = self::call('GET', "/api/articles/{$draftId}");
        self::assertSame(404, $response->getStatusCode());

        [$response, $body] = self::call('GET', "/api/articles/{$draftId}?status=draft");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Draft only', $body['data']['title']);

        [, $published] = self::call('GET', '/api/articles?filters[documentId][$eq]=' . $draftId);
        self::assertSame(0, $published['meta']['pagination']['total']);
        [, $drafts] = self::call('GET', '/api/articles?status=draft&filters[documentId][$eq]=' . $draftId);
        self::assertSame(1, $drafts['meta']['pagination']['total']);

        // the document service publishes the draft: both versions share the documentId
        self::strapi()->documents('api::article.article')->publish(['documentId' => $draftId]);
        [$response, $body] = self::call('GET', "/api/articles/{$draftId}");
        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($body['data']['publishedAt']);

        self::strapi()->documents('api::article.article')->unpublish(['documentId' => $draftId]);
        [$response] = self::call('GET', "/api/articles/{$draftId}");
        self::assertSame(404, $response->getStatusCode());

        // updating a published entry updates the draft and publishes it again
        [, $updated] = self::call('PUT', "/api/articles/{$documentId}", ['data' => ['title' => 'Republished']]);
        self::assertNotNull($updated['data']['publishedAt']);
        [, $draftVersion] = self::call('GET', "/api/articles/{$documentId}?status=draft");
        self::assertSame('Republished', $draftVersion['data']['title']);
    }

    #[Depends('testDraftAndPublish')]
    public function testDeleteRemovesEveryVersion(): void
    {
        [, $created] = self::call('POST', '/api/articles', ['data' => ['title' => 'To delete', 'authorName' => 'X']]);
        $documentId = $created['data']['documentId'];

        [$response] = self::call('DELETE', "/api/articles/{$documentId}");
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());

        [$response] = self::call('GET', "/api/articles/{$documentId}");
        self::assertSame(404, $response->getStatusCode());
        [$response] = self::call('GET', "/api/articles/{$documentId}?status=draft");
        self::assertSame(404, $response->getStatusCode());

        // like upstream, deleting an unknown document is a no-op answered with 204
        [$response] = self::call('DELETE', "/api/articles/{$documentId}");
        self::assertSame(204, $response->getStatusCode());
    }

    public function testSingleType(): void
    {
        [$response, $body] = self::call('GET', '/api/homepage');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['data' => null, 'error' => ['status' => 404, 'name' => 'NotFoundError', 'message' => 'Not Found', 'details' => []]], $body);

        [$response, $body] = self::call('PUT', '/api/homepage', ['data' => ['title' => 'Home', 'slug' => 'home', 'mediaType' => 'single']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Home', $body['data']['title']);
        $documentId = $body['data']['documentId'];

        [$response, $body] = self::call('GET', '/api/homepage');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Home', $body['data']['title']);
        self::assertSame($documentId, $body['data']['documentId']);

        [, $body] = self::call('PUT', '/api/homepage', ['data' => ['title' => 'Home 2']]);
        self::assertSame('Home 2', $body['data']['title']);
        self::assertSame($documentId, $body['data']['documentId'], 'a single type keeps one document');
        self::assertSame('home', $body['data']['slug']);

        [$response] = self::call('DELETE', '/api/homepage');
        self::assertSame(204, $response->getStatusCode());
        [$response] = self::call('GET', '/api/homepage');
        self::assertSame(404, $response->getStatusCode());
    }

    public function testComponentsAndDynamicZones(): void
    {
        [$response, $body] = self::call('POST', '/api/kitchensinks', ['data' => [
            'short_text' => 'hello',
            'single_compo' => ['name' => 'c1', 'test' => 't'],
            'repeatable_compo' => [['name' => 'r1'], ['name' => 'r2']],
            'dynamiczone' => [
                ['__component' => 'basic.simple', 'name' => 'dz1'],
                ['__component' => 'blog.test-como', 'name' => 'como'],
            ],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $documentId = $body['data']['documentId'];
        self::assertArrayNotHasKey('single_compo', $body['data'], 'components are not populated by default');

        [, $body] = self::call('GET', "/api/kitchensinks/{$documentId}?populate[single_compo]=true&populate[repeatable_compo]=true&populate[dynamiczone]=true");
        self::assertSame(['name' => 'c1', 'test' => 't'], array_intersect_key($body['data']['single_compo'], ['name' => 1, 'test' => 1]));
        self::assertSame(['r1', 'r2'], array_column($body['data']['repeatable_compo'], 'name'));
        self::assertSame(['basic.simple', 'blog.test-como'], array_column($body['data']['dynamiczone'], '__component'));
        self::assertSame('como', $body['data']['dynamiczone'][1]['name']);

        [, $star] = self::call('GET', "/api/kitchensinks/{$documentId}?populate=*");
        self::assertSame('c1', $star['data']['single_compo']['name']);
        self::assertSame([], $star['data']['multiple_media']);
        self::assertNull($star['data']['single_media']);

        [$response] = self::call('PUT', "/api/kitchensinks/{$documentId}", ['data' => ['repeatable_compo' => [['name' => 'r3']]]]);
        self::assertSame(200, $response->getStatusCode());
        [, $body] = self::call('GET', "/api/kitchensinks/{$documentId}?populate=repeatable_compo");
        self::assertSame(['r3'], array_column($body['data']['repeatable_compo'], 'name'));

        [$response, $error] = self::call('POST', '/api/kitchensinks', ['data' => ['single_compo' => ['test' => 'missing required name']]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['single_compo', 'name'], $error['error']['details']['errors'][0]['path']);
        self::assertSame('single_compo.name must be a `string` type, but the final value was: `null`.', $error['error']['details']['errors'][0]['message']);

        [$response, $error] = self::call('POST', '/api/kitchensinks', ['data' => ['dynamiczone' => [['__component' => 'does.not-exist']]]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('ValidationError', $error['error']['name']);
    }

    public function testValidationErrorEnvelope(): void
    {
        [$response, $body] = self::call('POST', '/api/articles', ['data' => ['title' => 1234]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($body['data']);
        self::assertSame(400, $body['error']['status']);
        self::assertSame('ValidationError', $body['error']['name']);
        self::assertSame('title must be a `string` type, but the final value was: `1234`.', $body['error']['message']);
        self::assertSame([[
            'path' => ['title'],
            'message' => 'title must be a `string` type, but the final value was: `1234`.',
            'name' => 'ValidationError',
            'value' => 1234,
        ]], $body['error']['details']['errors']);

        [$response, $body] = self::call('POST', '/api/articles', ['data' => []]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('title must be a `string` type, but the final value was: `null`.', $body['error']['message']);

        [$response, $body] = self::call('POST', '/api/articles', ['title' => 'no data wrapper']);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Missing "data" payload in the request body', $body['error']['message']);

        [$response, $body] = self::call('POST', '/api/articles', ['data' => ['title' => 'ok', 'unknownAttribute' => 1]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Invalid key unknownAttribute', $body['error']['message']);
    }

    public function testNotFoundAndUnknownRoutes(): void
    {
        [$response, $body] = self::call('GET', '/api/articles/doesnotexist');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NotFoundError', $body['error']['name']);

        [$response, $body] = self::call('GET', '/api/nope');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['data' => null, 'error' => ['status' => 404, 'name' => 'NotFoundError', 'message' => 'Not Found', 'details' => []]], $body);

        [$response] = self::call('PATCH', '/api/articles');
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testPolicyDenialIsForbidden(): void
    {
        [$response, $body] = self::call('GET', '/api/temps/denied');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['data' => null, 'error' => ['status' => 403, 'name' => 'PolicyError', 'message' => 'Policy Failed', 'details' => []]], $body);
    }

    public function testCustomRouteWithPolicyAndRouteMiddleware(): void
    {
        [$response, $body] = self::call('GET', '/api/temps/ping');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => ['pong' => true, 'uid' => 'api::temp.temp']], $body);
        self::assertSame('Address Middleware', $response->getHeaderLine('X-Strapi-Test'));
    }

    public function testCorsPreflight(): void
    {
        $response = self::request('OPTIONS', '/api/articles', null, [
            'Origin' => 'http://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type,Authorization,Origin,Accept', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('31536000', $response->getHeaderLine('Access-Control-Max-Age'));

        $response = self::request('GET', '/api/articles', null, ['Origin' => 'http://example.com']);
        self::assertSame('http://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testSecurityHeadersAndPoweredBy(): void
    {
        $response = self::request('GET', '/api/articles');

        self::assertSame('Strapi <strapi.io>', $response->getHeaderLine('X-Powered-By'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('off', $response->getHeaderLine('X-DNS-Prefetch-Control'));
        self::assertSame('noopen', $response->getHeaderLine('X-Download-Options'));
        self::assertSame('none', $response->getHeaderLine('X-Permitted-Cross-Domain-Policies'));
        self::assertFalse($response->hasHeader('X-XSS-Protection'), 'xssFilter is disabled by Strapi');

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("img-src 'self' data: blob: https://market-assets.strapi.io", $csp);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net", $csp);
        self::assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function testHealthCheckAndStaticFiles(): void
    {
        $response = self::request('HEAD', '/_health');
        self::assertSame(204, $response->getStatusCode());

        $response = self::request('GET', '/robots.txt');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('User-Agent', (string) $response->getBody());

        $response = self::request('GET', '/');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));

        // the admin bundle (build/) when built, otherwise the placeholder page: an HTML 200 either way
        foreach (['/admin', '/admin/content-manager/collection-types'] as $uri) {
            $response = self::request('GET', $uri);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('<html', (string) $response->getBody());
        }
    }

    public function testDocumentServiceDirectly(): void
    {
        $documents = self::strapi()->documents('api::category.category');

        $created = $documents->create(['data' => ['name' => 'Direct']]);
        self::assertNull($created['publishedAt']);
        self::assertSame('Direct', $created['name']);

        $found = $documents->findOne(['documentId' => $created['documentId'], 'status' => 'draft']);
        self::assertSame($created['id'], $found['id']);

        self::assertSame(0, $documents->count(['status' => 'published', 'filters' => ['name' => 'Direct']]));
        $documents->publish(['documentId' => $created['documentId']]);
        self::assertSame(1, $documents->count(['status' => 'published', 'filters' => ['name' => 'Direct']]));

        $documents->update(['documentId' => $created['documentId'], 'data' => ['name' => 'Direct 2']]);
        $published = $documents->findOne(['documentId' => $created['documentId'], 'status' => 'published']);
        self::assertSame('Direct', $published['name'], 'updating the draft does not touch the published version');

        $documents->discardDraft(['documentId' => $created['documentId']]);
        $draft = $documents->findOne(['documentId' => $created['documentId'], 'status' => 'draft']);
        self::assertSame('Direct', $draft['name']);

        $deleted = $documents->delete(['documentId' => $created['documentId']]);
        self::assertSame($created['documentId'], $deleted['documentId']);
        self::assertCount(2, $deleted['entries']);
    }
}
