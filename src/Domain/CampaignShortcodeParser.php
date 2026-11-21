<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Paired shortcode parser. Treats tag bodies as data, never as executable commands.
 *
 * @phpstan-type ParseOk array{ok: true, values: array<string, string>, order: list<string>}
 * @phpstan-type ParseErr array{ok: false, errors: list<string>}
 */
final class CampaignShortcodeParser
{
    /**
     * @param  list<string>  $allowedShortcodes  shortcode names from campaign mapping
     * @param  array<string, bool>  $allowMultiple  shortcode => allow duplicate assignments
     * @return ParseOk|ParseErr
     */
    public function parse(string $rawSource, array $allowedShortcodes, array $allowMultiple = [], bool $ignoreUnsupported = false): array
    {
        $allowed = [];
        foreach ($allowedShortcodes as $name) {
            $n = strtolower(trim((string) $name));
            if ($n !== '') {
                $allowed[$n] = true;
            }
        }
        if ($allowed === []) {
            return ['ok' => false, 'errors' => ['shortcode_mapping_empty']];
        }

        $len = strlen($rawSource);
        $pos = 0;
        $values = [];
        $order = [];
        $errors = [];
        $seen = [];

        while ($pos < $len) {
            // Skip whitespace between tags only
            if (preg_match('/\s+/A', $rawSource, $ws, 0, $pos) === 1) {
                $pos += strlen($ws[0]);
                continue;
            }

            if ($pos >= $len) {
                break;
            }

            if ($rawSource[$pos] !== '[') {
                return ['ok' => false, 'errors' => ['shortcode_outside_text']];
            }

            if (preg_match('/\[([a-z][a-z0-9_]*)\]/Ai', $rawSource, $open, 0, $pos) !== 1) {
                return ['ok' => false, 'errors' => ['shortcode_malformed_open']];
            }

            $name = strtolower($open[1]);
            $openLen = strlen($open[0]);
            $contentStart = $pos + $openLen;

            $isAllowed = isset($allowed[$name]);
            if (! $isAllowed && ! $ignoreUnsupported) {
                return ['ok' => false, 'errors' => ['shortcode_unsupported:'.$name]];
            }

            $closeNeedle = '[/'.$name.']';
            $closePos = $this->findClosingTag($rawSource, $contentStart, $name);
            if ($closePos === null) {
                return ['ok' => false, 'errors' => ['shortcode_unmatched:'.$name]];
            }

            $inner = substr($rawSource, $contentStart, $closePos - $contentStart);

            // Nested same-name or any shortcode open inside body → ambiguous
            if (preg_match('/\[[a-z][a-z0-9_]*\]/i', $inner) === 1
                || preg_match('/\[\/[a-z][a-z0-9_]*\]/i', $inner) === 1) {
                return ['ok' => false, 'errors' => ['shortcode_ambiguous:'.$name]];
            }

            if (! $isAllowed) {
                $pos = $closePos + strlen($closeNeedle);
                continue;
            }

            if (isset($seen[$name]) && ! ($allowMultiple[$name] ?? false)) {
                return ['ok' => false, 'errors' => ['shortcode_duplicate:'.$name]];
            }

            // Preserve inner content verbatim (do not strip marks-as-text / asterisks)
            $values[$name] = $inner;
            $order[] = $name;
            $seen[$name] = true;
            $pos = $closePos + strlen($closeNeedle);
        }

        if ($values === []) {
            return ['ok' => false, 'errors' => ['shortcode_empty']];
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'values' => $values, 'order' => $order];
    }

    private function findClosingTag(string $raw, int $from, string $name): ?int
    {
        $needle = '[/'.$name.']';
        $pos = stripos($raw, $needle, $from);
        if ($pos === false) {
            return null;
        }

        // Ensure no earlier unmatched closer of another name before this (handled by inner scan)
        return $pos;
    }
}
