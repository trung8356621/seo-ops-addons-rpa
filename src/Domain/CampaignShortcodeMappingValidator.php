<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Validates Campaign shortcode → Script variable mapping against published Script schema.
 * Requires coverage of ALL required Script variables (not only mapped subset).
 */
final class CampaignShortcodeMappingValidator
{
    public function __construct(
        private readonly CampaignScriptVariableSchema $schema = new CampaignScriptVariableSchema,
    ) {}

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, array<string, mixed>>  $variables
     * @return array{ok: bool, mapping?: array{entries: list<array{shortcode: string, variable: string}>}, errors?: list<string>}
     */
    public function validate(array $mapping, array $variables): array
    {
        $entriesRaw = $mapping['entries'] ?? null;
        if (! is_array($entriesRaw) || ! array_is_list($entriesRaw)) {
            return ['ok' => false, 'errors' => ['shortcode_mapping_invalid']];
        }
        if ($entriesRaw === []) {
            return ['ok' => false, 'errors' => ['shortcode_mapping_empty']];
        }

        $errors = [];
        $shortcodes = [];
        $varTargets = [];
        $normalized = [];

        foreach ($entriesRaw as $i => $row) {
            if (! is_array($row)) {
                $errors[] = 'shortcode_mapping_entry_invalid:'.$i;
                continue;
            }
            $shortcode = strtolower(trim((string) ($row['shortcode'] ?? '')));
            $variable = trim((string) ($row['variable'] ?? ''));
            if ($shortcode === '' || preg_match('/^[a-z][a-z0-9_]*$/', $shortcode) !== 1) {
                $errors[] = 'shortcode_name_invalid:'.$i;
                continue;
            }
            if ($variable === '') {
                $errors[] = 'shortcode_variable_invalid:'.$i;
                continue;
            }
            if (isset($shortcodes[$shortcode])) {
                $errors[] = 'shortcode_duplicate_name:'.$shortcode;
                continue;
            }
            if (isset($varTargets[$variable])) {
                $errors[] = 'shortcode_duplicate_variable:'.$variable;
                continue;
            }
            if (! isset($variables[$variable])) {
                $errors[] = 'shortcode_unknown_variable:'.$variable;
                continue;
            }
            $type = (string) ($variables[$variable]['type'] ?? 'string');
            if ($this->schema->isShortcodeUnsupported($type) && ! $this->schema->isShortcodeSupported($type)) {
                $errors[] = 'shortcode_incompatible_type:'.$variable.':'.$type;
                continue;
            }
            if (in_array($type, CampaignScriptVariableSchema::SHORTCODE_UNSUPPORTED_TYPES, true)) {
                $errors[] = 'shortcode_incompatible_type:'.$variable.':'.$type;
                continue;
            }
            if (! $this->schema->isShortcodeSupported($type)) {
                $errors[] = 'shortcode_incompatible_type:'.$variable.':'.$type;
                continue;
            }
            if ($type === 'enum' && (! is_array($variables[$variable]['enum'] ?? null) || $variables[$variable]['enum'] === [])) {
                $errors[] = 'shortcode_enum_values_required:'.$variable;
                continue;
            }

            $shortcodes[$shortcode] = true;
            $varTargets[$variable] = true;
            $normalized[] = ['shortcode' => $shortcode, 'variable' => $variable];
        }

        foreach ($this->schema->requiredKeys($variables) as $reqKey) {
            if (! isset($varTargets[$reqKey])) {
                $errors[] = 'required_variable_unmapped:'.$reqKey;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }

        return ['ok' => true, 'mapping' => ['entries' => $normalized]];
    }

    /**
     * @param  array{entries: list<array{shortcode: string, variable: string}}}  $mapping
     * @return array<string, string> shortcode => variable
     */
    public function shortcodeToVariable(array $mapping): array
    {
        $map = [];
        foreach ($mapping['entries'] as $row) {
            $map[$row['shortcode']] = $row['variable'];
        }

        return $map;
    }

    /**
     * @param  array{entries: list<array{shortcode: string, variable: string}}}  $mapping
     * @return list<string>
     */
    public function shortcodeNames(array $mapping): array
    {
        return array_values(array_map(
            static fn (array $e): string => $e['shortcode'],
            $mapping['entries'],
        ));
    }
}
