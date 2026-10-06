# strapi/logger

Monolog-based logger (port of `@strapi/logger`, which wraps winston).

| | |
| --- | --- |
| Upstream | [`@strapi/logger`](https://github.com/strapi/strapi/tree/develop/packages/utils/logger) |
| Namespace | `Strapi\Logger\` |
| Status | `foundation` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository
root for the naming rules. Winston "formats" become Monolog formatters or processors, "transports"
become handlers.

## Ported files

| Upstream | PHP | Public API |
| --- | --- | --- |
| `index.ts` | `src/index.php` | `Logger::createLogger(array $userConfiguration = []): Monolog\Logger`. Configuration keys keep winston's names: `level` (winston label `silly`…`error`), `format` (a Monolog formatter applied to transports without one), `transports` (Monolog handlers), `processors`, `name`, `stream` (console target, default `php://stdout`). |
| `constants.ts` | `src/constants.php` | `Constants::LEVELS` (winston npm levels), `LEVEL_LABEL` (`silly`), `LEVEL`, `MONOLOG_LEVELS`, `toMonologLevel()`, `toWinstonLabel()`. |
| `configs/default-configuration.ts` | `src/configs/default-configuration.php` | `DefaultConfiguration::create($stream)` → `{ level, levels, format: PrettyPrint, transports: [StreamHandler] }`. |
| `configs/output-file-configuration.ts` | `src/configs/output-file-configuration.php` | `OutputFileConfiguration::create($filename, $fileTransportOptions, ['consoleLevel' => ...])` → console transport + file transport (`error` and above, colors stripped). |
| `formats/pretty-print.ts` | `src/formats/pretty-print.php` | `PrettyPrint(['timestamps' => bool|format, 'colors' => bool])` formatter: `[YYYY-MM-DD HH:mm:ss.SSS] level: message`, winston's level colors, error stacks appended. |
| `formats/log-errors.ts` | `src/formats/log-errors.php` | `LogErrors` processor: appends the stack trace of `context['exception']` to the message. |
| `formats/exclude-colors.ts` | `src/formats/exclude-colors.php` | `ExcludeColors` formatter / `ExcludeColors::strip()`: removes ANSI color codes. |
| `formats/detailed-log.ts` | `src/formats/detailed-log.php` | `DetailedLog` formatter: `[timestamp] level: message`, colors stripped. |
| `formats/level-filter.ts` | `src/formats/level-filter.php` | `LevelFilter('error', 'warn')` → `accepts($level)`, `wrap($handler)` (a Monolog `FilterHandler`). |

Barrel files (`configs/index.ts`, `formats/index.ts`) are not ported.

## Deviations from upstream

- Winston levels are numeric priorities where *lower is more severe* and the configured level is a
  ceiling; Monolog's are a floor. `Constants::toMonologLevel()` maps labels so that `silly`/`debug`/
  `verbose` log everything (`Level::Debug`), `http`/`info` → `Info`, `warn` → `Warning`, `error` → `Error`.
  Log lines print the winston label (`warn`, not `WARNING`).
- A winston level filter is a format; in Monolog filtering happens per handler, hence `LevelFilter::wrap()`.
- `logger.silly()` / `logger.http()` / `logger.verbose()` have no Monolog counterpart: use `debug()` / `info()`.
