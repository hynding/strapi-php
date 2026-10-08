#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds parity.json: every upstream server-side source file, its git blob hash at the
 * tracked upstream version, and whether this repository has the mirrored PHP file.
 *
 * Requires a checkout of strapi/strapi. Point to it with --upstream=<path> (default
 * ../strapi). The upstream tag is taken from packages/core/strapi/composer.json unless
 * --tag=vX.Y.Z is given. With --diff=<old parity.json> the script also lists files whose
 * upstream blob changed since that file was produced (the "changed-upstream" list the
 * weekly tracking issue needs).
 *
 * Usage:
 *   php scripts/parity-map.php --upstream=../strapi
 *   php scripts/parity-map.php --upstream=../strapi --tag=v5.57.0 --diff=parity.json --out=parity.next.json
 */

$root = dirname(__DIR__);
$opts = getopt('', ['upstream::', 'tag::', 'diff::', 'out::', 'summary']);
/** getopt() yields `false` for a bare flag and a list for a repeated option: only a string is a value. */
$opt = static fn (string $name): ?string => is_string($opts[$name] ?? null) ? $opts[$name] : null;
$upstream = realpath($opt('upstream') ?? $root . '/../strapi') ?: '';
if ($upstream === '' || !is_dir($upstream . '/packages')) {
    fwrite(STDERR, "Upstream checkout not found. Pass --upstream=<path to strapi/strapi>.\n");
    exit(2);
}
$canonical = json_decode((string) file_get_contents($root . '/packages/core/strapi/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$tracked = preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', (string) $canonical['version']);
$tag = $opt('tag') ?? 'v' . $tracked;
$out = $opt('out') ?? $root . '/parity.json';

// Server-side files only: skip admin UIs, tests, type declarations, build output.
$cmd = sprintf('git -C %s ls-tree -r %s -- packages 2>/dev/null', escapeshellarg($upstream), escapeshellarg($tag));
exec($cmd, $lines, $code);
if ($code !== 0 || $lines === []) {
    // Shallow clones may not have the tag; fall back to HEAD and say so.
    exec(sprintf('git -C %s ls-tree -r HEAD -- packages', escapeshellarg($upstream)), $lines, $code);
    $sha = trim((string) shell_exec(sprintf('git -C %s rev-parse --short HEAD', escapeshellarg($upstream))));
    $tag = 'HEAD@' . $sha;
}

$isServerFile = static function (string $path): bool {
    if (!preg_match('/\.(ts|js|json)$/', $path)) {
        return false;
    }
    // An `admin/` UI folder sits right under a package root (packages/<group>/<name>/admin/...), and
    // packages/core/admin keeps its UI in admin/ and its server in server/ and ee/server/. Match the
    // folder after the package root only, so the admin package's server half is not dropped.
    $rest = implode('/', array_slice(explode('/', $path), 3));
    if (preg_match('#(^|/)(admin|ee/admin)/#', $rest) || preg_match('#/(__tests__|dist|node_modules|__mocks__)/#', $path)) {
        return false;
    }
    if (preg_match('/\.(test|spec)\.[tj]sx?$|\.d\.ts$|\.(config|setup)\.[mc]?js$|package\.json$|tsconfig.*\.json$|rollup\.config|lint-staged|jest\.config/', $path)) {
        return false;
    }
    // Only packages we mirror: everything under packages/* except cli/cloud and plugins/cloud
    if (preg_match('#^packages/(cli/cloud|plugins/cloud|admin-test-utils|utils/(eslint-config-custom|oxlint-config|tsconfig|vitest-config|typescript))/#', $path)) {
        return false;
    }
    return (bool) preg_match('#^packages/(core|plugins|providers|utils|generators|cli)/#', $path);
};

/*
 * Noise: upstream files that carry nothing to port. They are left out of the map entirely (like
 * tests and type declarations above). The rules are deliberately narrow:
 *
 * - tooling config at a package root: `vitest.config.ts`, `jsconfig.json` (not the ones inside
 *   create-strapi-app's project templates, which are template files);
 * - test helpers outside `__tests__`: a `test-utils/` folder, a `tests/` folder at a package root
 *   (msw servers, jest setup), `src/test/` mocks, `tests/setup.ts`;
 * - benchmarks: `scripts/bench-*`;
 * - by content ($isTypeOnlyOrBarrel below): a `.ts`/`.js` file whose every top-level statement is
 *   an import, a type-level declaration (`type`, `interface`, `declare`, a `namespace` holding only
 *   types), a re-export (`export * from`, `export { a } from`, `export { a }` of imported names,
 *   `export default <identifier>`), or an object of imported names (`export default { a, b }`,
 *   CommonJS `module.exports = { a: require('./a') }`). One executable statement — a `const`, a
 *   function, a class, an enum, a call — makes the file real code. `shared/contracts/*.ts`,
 *   `types.ts` and barrel `index.ts` files are what this matches in practice. It only applies to
 *   files without a PHP mirror and outside packages/core/types (see the loop below).
 */
$isNoisePath = static function (string $path): bool {
    return preg_match('#^packages/[^/]+/[^/]+/(vitest\.config\.ts|jsconfig\.json)$#', $path) === 1
        || preg_match('#/test-utils/#', $path) === 1
        || preg_match('#^packages/[^/]+/[^/]+/tests/#', $path) === 1
        || preg_match('#^packages/[^/]+/[^/]+/src/test/#', $path) === 1
        || preg_match('#/scripts/bench-[^/]+$#', $path) === 1;
};

$isTypeOnlyOrBarrel = static function (string $source): bool {
    // comments and the CommonJS prologue carry no code
    $s = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $s = preg_replace('#^\s*//.*$#m', '', $s) ?? $s;
    $s = preg_replace('#^[\'"]use strict[\'"];?\s*$#m', '', $s) ?? $s;
    $lines = explode("\n", $s);
    $identifierList = '\s*(?:[A-Za-z_$][\w$]*(?:\s*:\s*(?:[A-Za-z_$][\w$]*|require\([\'"][^\'"]+[\'"]\)))?\s*,?\s*)*';
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = rtrim($lines[$i]);
        if ($line === '' || preg_match('/^\s/', $line) === 1) {
            continue; // blank, or the indented body of a statement opened on a checked line
        }
        if (preg_match('/^(namespace|export\s+namespace|declare\s+namespace)\b/', $line) === 1) {
            // a namespace may hold values: everything up to its closing brace must be type-level
            for ($j = $i + 1; $j < $n && !str_starts_with($lines[$j], '}'); $j++) {
                if (preg_match('/^\s+(export\s+)?(const|let|var|function|class|abstract\s+class|enum)\b/', $lines[$j]) === 1) {
                    return false;
                }
            }
            $i = $j;
            continue;
        }
        if (preg_match('/^(export\s+default\s*\{|module\.exports\s*=\s*\{)(.*)$/', $line, $m) === 1) {
            // an object of imported names: collect it up to the closing brace
            $body = $m[2];
            for ($j = $i + 1; $j < $n && !str_contains($body, '}'); $j++) {
                $body .= ' ' . $lines[$j];
            }
            if (preg_match('/^' . $identifierList . '\}\s*;?\s*$/', trim($body)) !== 1) {
                return false;
            }
            $i = $j - 1;
            continue;
        }
        $allowed = '/^('
            . 'import\s'                                               // import ... (multi-line bodies are indented)
            . '|const\s+[A-Za-z_$][\w$]*\s*=\s*require\([\'"][^\'"]+[\'"]\);?$' // CommonJS import
            . '|export\s+(type|interface|declare)\b'                    // exported type-level declarations
            . '|(type|interface|declare)\s'                             // local type-level declarations
            . '|export\s+\*'                                            // export * from / export * as x from
            . '|export\s+(type\s+)?\{'                                  // export { a } [from], export type { a }
            . '|export\s+default\s+[A-Za-z_$][\w$]*;?$'                 // export default <identifier>
            . '|[}\])>|&]'                                              // closing or continuing a type at column 0
            . ')/';
        if (preg_match($allowed, $line) !== 1) {
            return false;
        }
    }

    return true;
};

/*
 * Not applicable: Node-only upstream files with no counterpart in a PHP runtime. Kept out of the
 * file list and reported under `notApplicable` with the reason (each package README says the same).
 */
$notApplicable = [
    // @strapi/strapi — browser entry points of the admin bundle (served from @strapi/admin's build)
    'packages/core/strapi/src/admin.ts' => 'React entry point of the admin bundle (the admin half is upstream JS)',
    'packages/core/strapi/src/admin-test.ts' => 'React test entry point of the admin bundle',
    // @strapi/strapi — admin build toolchain internals: the PHP edition writes a Vite config and runs
    // the upstream Node toolchain (node/vite/config.php), which resolves these itself
    'packages/core/strapi/src/node/core/admin-vite-alias-modules.ts' => 'Vite alias resolution inside the Node admin build',
    'packages/core/strapi/src/node/core/admin-vite-aliases.ts' => 'Vite alias resolution inside the Node admin build',
    'packages/core/strapi/src/node/core/admin-vite-optimize-exclude.ts' => 'Vite optimizeDeps tuning inside the Node admin build',
    'packages/core/strapi/src/node/core/admin-vite-singleton-modules.ts' => 'Vite dedupe list inside the Node admin build',
    'packages/core/strapi/src/node/core/aliases.ts' => 'Node module aliases of the admin build',
    'packages/core/strapi/src/node/core/config.ts' => 'loads src/admin/*.config.{js,ts} with Node; the generated Vite config applies it',
    'packages/core/strapi/src/node/core/dependencies.ts' => 'npm dependency checks/installs; replaced by EnsureAdminDependencies (VERSIONING.md check)',
    'packages/core/strapi/src/node/core/linked-packages.ts' => 'npm-linked packages in the Node admin build',
    'packages/core/strapi/src/node/core/managers.ts' => 'npm/yarn/pnpm detection',
    'packages/core/strapi/src/node/core/monorepo.ts' => 'upstream monorepo detection for the admin build',
    'packages/core/strapi/src/node/core/resolve-module.ts' => 'Node module resolution',
    'packages/core/strapi/src/node/core/scan-roots.ts' => 'Node module scanning for the admin build',
    'packages/core/strapi/src/node/vite/plugins.ts' => 'Vite plugins of the Node admin build',
    'packages/core/strapi/src/node/vite/watch.ts' => 'Vite middleware mode (HMR) inside a Node server',
    'packages/core/strapi/src/node/webpack/build.ts' => 'webpack bundler (deprecated upstream, refused by `build`)',
    'packages/core/strapi/src/node/webpack/config.ts' => 'webpack bundler (deprecated upstream, refused by `build`)',
    'packages/core/strapi/src/node/webpack/watch.ts' => 'webpack bundler (deprecated upstream, refused by `build`)',
    'packages/core/strapi/src/load/package-path.ts' => 'require.resolve() of an npm package',
    // @strapi/strapi — CLI utilities for Node/TypeScript projects
    'packages/core/strapi/src/cli/utils/get-inquirer.ts' => 'lazy import() of inquirer (symfony/console asks the questions)',
    'packages/core/strapi/src/cli/utils/pkg.ts' => 'package.json export-map validation for Node package builds',
    'packages/core/strapi/src/cli/utils/tsconfig.ts' => 'loads tsconfig.json with the TypeScript compiler',
    'packages/core/strapi/src/cli/utils/try-quick-outdir.ts' => 'TypeScript outDir detection (there is no TS build)',
    'packages/core/strapi/src/cli/commands/ts/generate-types.ts' => 'TypeScript typings for a TS server (@strapi/typescript-utils); a PHP project has no TS server code',
    // @strapi/utils — npm / require() helpers
    'packages/core/utils/src/import-default.ts' => 'require() interop with ES module default exports',
];

/*
 * Aliases: upstream files ported under another PHP path on purpose. Each says why.
 */
$aliases = [
    // Koa itself is replaced by Context/Compose/Router; createKoaApp's custom response methods
    // (send, created, deleted, the error helpers) are Context methods
    'packages/core/core/src/services/server/koa.ts' => 'packages/core/core/src/services/server/context.php',
    // `createMetadata(models)` is `Metadata::create($models)`: index.ts would map to the same class
    // name as metadata.ts (rule 2: an index file takes its directory's name)
    'packages/core/database/src/metadata/index.ts' => 'packages/core/database/src/metadata/metadata.php',
    // `preferred-pm`'s lazy import is ported inside PackageManager (packages/utils/upgrade uses it)
    'packages/core/utils/src/get-preferred-pm.ts' => 'packages/core/utils/src/package-manager.php',
    // the Composer binary (no .js extension)
    'packages/core/strapi/bin/strapi.js' => 'packages/core/strapi/bin/strapi',
];

$phpPathFor = static function (string $upstreamPath) use ($aliases): string {
    if (isset($aliases[$upstreamPath])) {
        return $aliases[$upstreamPath];
    }
    $p = preg_replace('/\.(ts|js)$/', '.php', $upstreamPath) ?? $upstreamPath;
    // upstream packages/utils/typescript → ours packages/utils/type-utils (not mirrored yet)
    return $p;
};

/** Reads upstream blobs through one `git cat-file --batch` process. */
$readBlob = (static function () use ($upstream): \Closure {
    $proc = null;
    $pipes = [];

    return static function (string $hash) use (&$proc, &$pipes, $upstream): string {
        if ($proc === null) {
            $proc = proc_open(['git', '-C', $upstream, 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        }
        fwrite($pipes[0], $hash . "\n");
        fflush($pipes[0]);
        $header = (string) fgets($pipes[1]);
        if (preg_match('/^\S+ blob (\d+)$/', trim($header), $m) !== 1) {
            return '';
        }
        $size = (int) $m[1];
        $data = '';
        while (strlen($data) < $size && !feof($pipes[1])) {
            $data .= (string) fread($pipes[1], max(1, $size - strlen($data)));
        }
        fgets($pipes[1]); // trailing newline

        return $data;
    };
})();

// folders (below a package root) whose own LICENSE is the Enterprise licence
$enterpriseDirs = [];
foreach ($lines as $line) {
    // a nested folder's own LICENSE, or a package root LICENSE that covers the whole package
    // ("All software that resides within this directory": content-releases, review-workflows);
    // a root LICENSE that only covers ee/ folders does not make the package EE
    if (preg_match('/^\d+ blob ([0-9a-f]+)\t(packages\/[^\/]+\/[^\/]+(?:\/.+)?)\/LICENSE$/', $line, $m) !== 1) {
        continue;
    }
    $licence = (string) shell_exec(sprintf('git -C %s cat-file -p %s 2>/dev/null', escapeshellarg($upstream), escapeshellarg($m[1])));
    $isPackageRoot = substr_count($m[2], '/') === 2;
    if (str_contains($licence, 'Enterprise License') && (!$isPackageRoot || str_contains($licence, 'resides within this directory'))) {
        $enterpriseDirs[] = $m[2] . '/';
    }
}
$isUnderEnterpriseLicence = static function (string $path) use ($enterpriseDirs): bool {
    foreach ($enterpriseDirs as $dir) {
        if (str_starts_with($path, $dir)) {
            return true;
        }
    }

    return false;
};

$entries = [];
$noise = 0;
$notApplicableFound = [];
foreach ($lines as $line) {
    // <mode> blob <hash>\t<path>
    if (!preg_match('/^\d+ blob ([0-9a-f]+)\t(.+)$/', $line, $m)) {
        continue;
    }
    [, $hash, $path] = $m;
    if (!$isServerFile($path)) {
        continue;
    }
    if ($isNoisePath($path)) {
        $noise++;
        continue;
    }
    // Enterprise Edition code is under Strapi's EE licence, not MIT: tracked, but blocked until the
    // licence is confirmed. That is everything under an ee/ folder, and every folder below a package
    // root that carries its own Enterprise LICENSE (content-manager's history/ and preview/).
    // Its contents are never read (so never classified as noise).
    $isEe = preg_match('#^packages/[^/]+/[^/]+/(.*/)?ee/#', $path) === 1 || $isUnderEnterpriseLicence($path);
    // A type-only or barrel file the port mirrors anyway stays tracked: the registries a module
    // needs (`services/index.ts` → a file returning the map) and strapi/types, whose port *is* the
    // type declarations (CLAUDE.md rule 5).
    if (
        !$isEe
        && preg_match('/\.(ts|js)$/', $path) === 1
        && !str_starts_with($path, 'packages/core/types/')
        && !file_exists($root . '/' . $phpPathFor($path))
        && $isTypeOnlyOrBarrel($readBlob($hash))
    ) {
        $noise++;
        continue;
    }
    // a file listed as not applicable that gets a PHP mirror after all counts as ported
    if (isset($notApplicable[$path]) && !file_exists($root . '/' . $phpPathFor($path))) {
        $notApplicableFound[$path] = $notApplicable[$path];
        continue;
    }
    $php = $phpPathFor($path);
    $entries[$path] = [
        'upstream' => $hash,
        'php' => $php,
        'ported' => file_exists($root . '/' . $php),
    ];
    if (isset($aliases[$path])) {
        $entries[$path]['alias'] = true;
    }
    if ($isEe) {
        $entries[$path]['ee'] = true;
    }
}
ksort($entries);
ksort($notApplicableFound);

$changed = [];
$diff = $opt('diff');
if ($diff !== null && is_file($diff)) {
    $old = json_decode((string) file_get_contents($diff), true, 512, JSON_THROW_ON_ERROR);
    $oldFiles = $old['files'] ?? [];
    foreach ($entries as $path => $e) {
        if (!isset($oldFiles[$path])) {
            $changed[$path] = 'added-upstream';
        } elseif ($oldFiles[$path]['upstream'] !== $e['upstream']) {
            $changed[$path] = 'changed-upstream';
        }
    }
    foreach (array_keys($oldFiles) as $path) {
        if (!isset($entries[$path])) {
            $changed[$path] = 'removed-upstream';
        }
    }
}

$ported = count(array_filter($entries, static fn ($e) => $e['ported']));
$ee = count(array_filter($entries, static fn ($e) => $e['ee'] ?? false));
$result = [
    'upstreamTag' => $tag,
    'generatedAt' => gmdate('c'),
    'totals' => ['files' => count($entries), 'ported' => $ported, 'ee' => $ee, 'notApplicable' => count($notApplicableFound), 'noise' => $noise],
    'changed' => $changed,
    'files' => $entries,
    'notApplicable' => $notApplicableFound,
];
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// Per-package summary for humans and the tracking issue.
$byPackage = [];
foreach ($entries as $path => $e) {
    $pkg = implode('/', array_slice(explode('/', $path), 0, 3));
    $byPackage[$pkg]['files'] = ($byPackage[$pkg]['files'] ?? 0) + 1;
    $byPackage[$pkg]['ported'] = ($byPackage[$pkg]['ported'] ?? 0) + ($e['ported'] ? 1 : 0);
}
printf("Upstream %s: %d server files (%d EE, blocked), %d ported (%.1f%%); %d not applicable, %d noise (types/barrels/tooling) skipped. Written to %s\n", $tag, count($entries), $ee, $ported, $ported * 100 / max(1, count($entries)), count($notApplicableFound), $noise, $out);
foreach ($byPackage as $pkg => $c) {
    printf("  %-48s %4d / %4d\n", $pkg, $c['ported'], $c['files']);
}
if ($changed !== []) {
    printf("\n%d files changed upstream since the previous parity map:\n", count($changed));
    foreach ($changed as $path => $kind) {
        printf("  %-18s %s\n", $kind, $path);
    }
}
