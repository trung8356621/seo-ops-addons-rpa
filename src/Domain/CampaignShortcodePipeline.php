<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Canonical shortcode → typed Script input pipeline for all callers.
 */
final class CampaignShortcodePipeline
{
    public function __construct(
        private readonly CampaignShortcodeRichText $richText = new CampaignShortcodeRichText,
        private readonly CampaignShortcodeParser $parser = new CampaignShortcodeParser,
        private readonly CampaignShortcodeMappingValidator $mappingValidator = new CampaignShortcodeMappingValidator,
        private readonly CampaignShortcodeTypedValidator $typed = new CampaignShortcodeTypedValidator,
        private readonly CampaignInputSanitizer $sanitizer = new CampaignInputSanitizer,
        private readonly CampaignScriptVariableSchema $schema = new CampaignScriptVariableSchema,
    ) {}

    /**
     * @param  array{entries: list<array{shortcode: string, variable: string}}}  $mapping
     * @param  array<string, array<string, mixed>>  $variables
     * @return array{ok: bool, input?: array<string, mixed>, raw_source?: string, errors?: list<string>}
     */
    public function process(string $rawSource, array $mapping, array $variables): array
    {
        $canonical = $this->richText->assertCanonical($rawSource);
        if (! ($canonical['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $canonical['errors'] ?? ['richtext_format_unsupported']];
        }
        $raw = (string) ($canonical['raw'] ?? $rawSource);

        $names = $this->mappingValidator->shortcodeNames($mapping);
        $allowMultiple = [];
        $shortToVar = $this->mappingValidator->shortcodeToVariable($mapping);
        foreach ($shortToVar as $short => $varKey) {
            if ((bool) ($variables[$varKey]['allow_multiple'] ?? false)) {
                $allowMultiple[$short] = true;
            }
        }

        $parsed = $this->parser->parse($raw, $names, $allowMultiple);
        if (! ($parsed['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['shortcode_parse_failed']];
        }

        /** @var array<string, string> $shortValues */
        $shortValues = $parsed['values'] ?? [];
        $input = [];
        foreach ($shortValues as $short => $value) {
            $varKey = $shortToVar[$short] ?? null;
            if ($varKey === null) {
                return ['ok' => false, 'errors' => ['shortcode_unsupported:'.$short]];
            }
            $input[$varKey] = $value;
        }

        // Optional mapped vars may be omitted; required Script vars checked in typed validator.
        $typed = $this->typed->validate($input, $variables);
        if (! ($typed['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $typed['errors'] ?? ['content_input_invalid']];
        }

        $variableNames = array_keys($variables);
        $sanitized = $this->sanitizer->sanitize($typed['input'] ?? [], $variableNames);
        if (! ($sanitized['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $sanitized['errors'] ?? ['content_input_invalid']];
        }

        $finalInput = $sanitized['input'] ?? [];
        if ($finalInput === []) {
            return ['ok' => false, 'errors' => ['content_input_empty']];
        }

        return ['ok' => true, 'input' => $finalInput, 'raw_source' => $raw];
    }

    /**
     * Re-validate already-stored typed input (dispatch / start). No reparse.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $variables
     * @return array{ok: bool, input?: array<string, mixed>, errors?: list<string>}
     */
    public function validateStoredInput(array $input, array $variables): array
    {
        $typed = $this->typed->validate($input, $variables);
        if (! ($typed['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $typed['errors'] ?? ['content_input_invalid']];
        }
        $sanitized = $this->sanitizer->sanitize($typed['input'] ?? [], array_keys($variables));
        if (! ($sanitized['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $sanitized['errors'] ?? ['content_input_invalid']];
        }
        $final = $sanitized['input'] ?? [];
        if ($final === []) {
            return ['ok' => false, 'errors' => ['content_input_empty']];
        }

        return ['ok' => true, 'input' => $final];
    }

    public function schema(): CampaignScriptVariableSchema
    {
        return $this->schema;
    }

    public function mappingValidator(): CampaignShortcodeMappingValidator
    {
        return $this->mappingValidator;
    }
}
