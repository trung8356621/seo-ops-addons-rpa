<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Canonical Rich Text serialization for Campaign shortcode content.
 *
 * Stored form is shortcode-delimited plain text, e.g.:
 *   [body]Paragraph one.\n\nParagraph two.[/body]
 *
 * Tiptap editors must serialize to this form before API submit.
 * Backend rejects uncontrolled HTML / Tiptap JSON / mark-only payloads
 * rather than auto-stripping Bold/Italic into lost content.
 */
final class CampaignShortcodeRichText
{
    /**
     * @return array{ok: bool, raw?: string, errors?: list<string>}
     */
    public function assertCanonical(string $rawSource): array
    {
        $raw = $rawSource;
        if (trim($raw) === '') {
            return ['ok' => false, 'errors' => ['raw_source_empty']];
        }

        $trimmedStart = ltrim($raw);
        // Tiptap JSON document (object only — shortcodes also start with `[`)
        if (str_starts_with($trimmedStart, '{')) {
            $decoded = json_decode($trimmedStart, true);
            if (is_array($decoded) && (isset($decoded['type']) || isset($decoded['content']))) {
                return ['ok' => false, 'errors' => ['richtext_format_unsupported']];
            }
        }

        // Uncontrolled HTML
        if (preg_match('/<\s*(p|div|span|strong|em|b|i|br|h[1-6]|ul|ol|li|a|img|table|html|body)\b/i', $raw) === 1) {
            return ['ok' => false, 'errors' => ['richtext_format_unsupported']];
        }

        // Must contain at least one shortcode open tag to be shortcode content
        if (preg_match('/\[[a-z][a-z0-9_]*\]/i', $raw) !== 1) {
            return ['ok' => false, 'errors' => ['richtext_format_unsupported']];
        }

        return ['ok' => true, 'raw' => $raw];
    }
}
