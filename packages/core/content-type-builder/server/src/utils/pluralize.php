<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Utils;

/**
 * Not an upstream file: the subset of npm `pluralize` 8.0.0 the content-type builder uses
 * (`pluralize(displayName)` for component collection names), with the same rule tables and
 * case restoration so collection names match upstream's byte for byte.
 */
final class Pluralize
{
    /** @var list<array{0: string, 1: string}>|null */
    private static ?array $pluralRules = null;

    /** @var array<string, true> */
    private static array $uncountables = [];

    /** @var array<string, string> */
    private static array $irregularPlurals = [];

    /** @var array<string, string> */
    private static array $irregularSingles = [];

    private const array IRREGULAR = [
        // Pronouns.
        ['I', 'we'], ['me', 'us'], ['he', 'they'], ['she', 'they'], ['them', 'them'],
        ['myself', 'ourselves'], ['yourself', 'yourselves'], ['itself', 'themselves'],
        ['herself', 'themselves'], ['himself', 'themselves'], ['themself', 'themselves'],
        ['is', 'are'], ['was', 'were'], ['has', 'have'], ['this', 'these'], ['that', 'those'],
        // Words ending in with a consonant and `o`.
        ['echo', 'echoes'], ['dingo', 'dingoes'], ['volcano', 'volcanoes'], ['tornado', 'tornadoes'], ['torpedo', 'torpedoes'],
        // Ends with `us`.
        ['genus', 'genera'], ['viscus', 'viscera'],
        // Ends with `ma`.
        ['stigma', 'stigmata'], ['stoma', 'stomata'], ['dogma', 'dogmata'], ['lemma', 'lemmata'], ['schema', 'schemata'], ['anathema', 'anathemata'],
        // Other irregular rules.
        ['ox', 'oxen'], ['axe', 'axes'], ['die', 'dice'], ['yes', 'yeses'], ['foot', 'feet'], ['eave', 'eaves'],
        ['goose', 'geese'], ['tooth', 'teeth'], ['quiz', 'quizzes'], ['human', 'humans'], ['proof', 'proofs'],
        ['carve', 'carves'], ['valve', 'valves'], ['looey', 'looies'], ['thief', 'thieves'], ['groove', 'grooves'],
        ['pickaxe', 'pickaxes'], ['passerby', 'passersby'],
    ];

    /** PCRE versions of the JavaScript rule regexes (all case-insensitive). */
    private const array PLURAL = [
        ['s?$', 's'],
        ['[^\x{0000}-\x{007F}]$', '$0'],
        ['([^aeiou]ese)$', '$1'],
        ['(ax|test)is$', '$1es'],
        ['(alias|[^aou]us|t[lm]as|gas|ris)$', '$1es'],
        ['(e[mn]u)s?$', '$1s'],
        ['([^l]ias|[aeiou]las|[ejzr]as|[iu]am)$', '$1'],
        ['(alumn|syllab|vir|radi|nucle|fung|cact|stimul|termin|bacill|foc|uter|loc|strat)(?:us|i)$', '$1i'],
        ['(alumn|alg|vertebr)(?:a|ae)$', '$1ae'],
        ['(seraph|cherub)(?:im)?$', '$1im'],
        ['(her|at|gr)o$', '$1oes'],
        ['(agend|addend|millenni|dat|extrem|bacteri|desiderat|strat|candelabr|errat|ov|symposi|curricul|automat|quor)(?:a|um)$', '$1a'],
        ['(apheli|hyperbat|periheli|asyndet|noumen|phenomen|criteri|organ|prolegomen|hedr|automat)(?:a|on)$', '$1a'],
        ['sis$', 'ses'],
        ['(?:(kni|wi|li)fe|(ar|l|ea|eo|oa|hoo)f)$', '$1$2ves'],
        ['([^aeiouy]|qu)y$', '$1ies'],
        ['([^ch][ieo][ln])ey$', '$1ies'],
        ['(x|ch|ss|sh|zz)$', '$1es'],
        ['(matr|cod|mur|sil|vert|ind|append)(?:ix|ex)$', '$1ices'],
        ['\b((?:tit)?m|l)(?:ice|ouse)$', '$1ice'],
        ['(pe)(?:rson|ople)$', '$1ople'],
        ['(child)(?:ren)?$', '$1ren'],
        ['eaux$', '$0'],
        ['m[ae]n$', 'men'],
        ['^thou$', 'you'],
    ];

    private const array UNCOUNTABLE_WORDS = [
        'adulthood', 'advice', 'agenda', 'aid', 'aircraft', 'alcohol', 'ammo', 'analytics', 'anime', 'athletics',
        'audio', 'bison', 'blood', 'bream', 'buffalo', 'butter', 'carp', 'cash', 'chassis', 'chess', 'clothing',
        'cod', 'commerce', 'cooperation', 'corps', 'debris', 'diabetes', 'digestion', 'elk', 'energy', 'equipment',
        'excretion', 'expertise', 'firmware', 'flounder', 'fun', 'gallows', 'garbage', 'graffiti', 'hardware',
        'headquarters', 'health', 'herpes', 'highjinks', 'homework', 'housework', 'information', 'jeans', 'justice',
        'kudos', 'labour', 'literature', 'machinery', 'mackerel', 'mail', 'media', 'mews', 'moose', 'music', 'mud',
        'manga', 'news', 'only', 'personnel', 'pike', 'plankton', 'pliers', 'police', 'pollution', 'premises',
        'rain', 'research', 'rice', 'salmon', 'scissors', 'series', 'sewage', 'shambles', 'shrimp', 'software',
        'species', 'staff', 'swine', 'tennis', 'traffic', 'transportation', 'trout', 'tuna', 'wealth', 'welfare',
        'whiting', 'wildebeest', 'wildlife', 'you',
    ];

    private const array UNCOUNTABLE_REGEXES = [
        'pok[eé]mon$', '[^aeiou]ese$', 'deer$', 'fish$', 'measles$', 'o[iu]s$', 'pox$', 'sheep$',
    ];

    private static function init(): void
    {
        if (self::$pluralRules !== null) {
            return;
        }

        foreach (self::IRREGULAR as [$single, $plural]) {
            self::$irregularSingles[mb_strtolower($single)] = mb_strtolower($plural);
            self::$irregularPlurals[mb_strtolower($plural)] = mb_strtolower($single);
        }

        $rules = [];
        foreach (self::PLURAL as [$rule, $replacement]) {
            $rules[] = ['/' . $rule . '/iu', $replacement];
        }
        foreach (self::UNCOUNTABLE_WORDS as $word) {
            self::$uncountables[$word] = true;
        }
        foreach (self::UNCOUNTABLE_REGEXES as $rule) {
            $rules[] = ['/' . $rule . '/iu', '$0'];
        }
        self::$pluralRules = $rules;
    }

    /** `pluralize(word)` / `pluralize.plural(word)`. */
    public static function plural(string $word): string
    {
        self::init();
        $token = mb_strtolower($word);

        if (array_key_exists($token, self::$irregularPlurals)) {
            return self::restoreCase($word, $token);
        }

        if (array_key_exists($token, self::$irregularSingles)) {
            return self::restoreCase($word, self::$irregularSingles[$token]);
        }

        return self::sanitizeWord($token, $word, self::$pluralRules ?? []);
    }

    /** @param list<array{0: string, 1: string}> $rules */
    private static function sanitizeWord(string $token, string $word, array $rules): string
    {
        if ($token === '' || array_key_exists($token, self::$uncountables)) {
            return $word;
        }

        for ($i = count($rules) - 1; $i >= 0; $i--) {
            if (preg_match($rules[$i][0], $word) === 1) {
                return self::replace($word, $rules[$i]);
            }
        }

        return $word;
    }

    /** @param array{0: string, 1: string} $rule */
    private static function replace(string $word, array $rule): string
    {
        if (preg_match($rule[0], $word, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $word;
        }

        [$match, $byteOffset] = $m[0];
        $args = array_map(static fn (array $g): string => $g[1] < 0 ? '' : $g[0], $m);
        $result = (string) preg_replace_callback('/\$(\d{1,2})/', static fn (array $x): string => $args[(int) $x[1]] ?? '', $rule[1]);

        if ($match === '') {
            // upstream reads `word[index - 1]`, index being the match offset when the rule has no group
            $charOffset = mb_strlen(substr($word, 0, $byteOffset));
            $restored = self::restoreCase(mb_substr($word, $charOffset - 1, 1), $result);
        } else {
            $restored = self::restoreCase($match, $result);
        }

        return substr($word, 0, $byteOffset) . $restored . substr($word, $byteOffset + strlen($match));
    }

    private static function restoreCase(string $word, string $token): string
    {
        if ($word === $token) {
            return $token;
        }

        if ($word === mb_strtolower($word)) {
            return mb_strtolower($token);
        }

        if ($word === mb_strtoupper($word)) {
            return mb_strtoupper($token);
        }

        $first = mb_substr($word, 0, 1);
        if ($first === mb_strtoupper($first)) {
            return mb_strtoupper(mb_substr($token, 0, 1)) . mb_strtolower(mb_substr($token, 1));
        }

        return mb_strtolower($token);
    }
}
