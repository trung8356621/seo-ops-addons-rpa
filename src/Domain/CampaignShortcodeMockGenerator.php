<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Type-aware mock Article generator from Campaign mapping + Script variable schema.
 * No AI. No platform branches. Caller chooses only article count.
 */
final class CampaignShortcodeMockGenerator
{
    public const MAX_BATCH = 50;

    public function __construct(
        private readonly CampaignShortcodePipeline $pipeline = new CampaignShortcodePipeline,
        private readonly CampaignShortcodeMappingValidator $mappingValidator = new CampaignShortcodeMappingValidator,
    ) {}

    /**
     * @param  array{entries: list<array{shortcode: string, variable: string}}}  $mapping
     * @param  array<string, array<string, mixed>>  $variables
     * @return array{ok: bool, articles?: list<array{external_ref: string, raw_source: string, input: array<string, mixed>}>, errors?: list<string>}
     */
    public function generate(int $count, array $mapping, array $variables): array
    {
        if ($count < 1) {
            return ['ok' => false, 'errors' => ['mock_count_invalid']];
        }
        if ($count > self::MAX_BATCH) {
            return ['ok' => false, 'errors' => ['mock_count_exceeds_max']];
        }

        $mapCheck = $this->mappingValidator->validate(
            ['entries' => $mapping['entries']],
            $variables,
        );
        if (! ($mapCheck['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $mapCheck['errors'] ?? ['shortcode_mapping_invalid']];
        }
        /** @var array{entries: list<array{shortcode: string, variable: string}}} $mapping */
        $mapping = $mapCheck['mapping'] ?? $mapping;
        $shortToVar = $this->mappingValidator->shortcodeToVariable($mapping);

        $articles = [];
        $refs = [];
        for ($i = 1; $i <= $count; $i++) {
            $ref = 'mock-'.bin2hex(random_bytes(8)).'-'.$i;
            if (isset($refs[$ref])) {
                return ['ok' => false, 'errors' => ['mock_external_ref_collision']];
            }
            $refs[$ref] = true;

            $parts = [];
            $builtInput = [];
            foreach ($shortToVar as $short => $varKey) {
                $var = $variables[$varKey] ?? null;
                if (! is_array($var)) {
                    return ['ok' => false, 'errors' => ['mock_unknown_variable:'.$varKey]];
                }
                $required = (bool) ($var['required'] ?? false);
                // Include optional mapped vars when generatable
                $sample = $this->sampleValue($varKey, $var, $ref, $i);
                if (! ($sample['ok'] ?? false)) {
                    if (! $required) {
                        continue;
                    }

                    return ['ok' => false, 'errors' => $sample['errors'] ?? ['mock_unsupported_type:'.$varKey]];
                }
                $value = $sample['value'];
                $builtInput[$varKey] = $value;
                $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $parts[] = '['.$short.']'.$text.'[/'.$short.']';
            }

            if ($parts === []) {
                return ['ok' => false, 'errors' => ['mock_empty']];
            }

            $raw = implode("\n", $parts);
            $processed = $this->pipeline->process($raw, $mapping, $variables);
            if (! ($processed['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $processed['errors'] ?? ['mock_pipeline_failed']];
            }

            $articles[] = [
                'external_ref' => $ref,
                'raw_source' => (string) ($processed['raw_source'] ?? $raw),
                'input' => $processed['input'] ?? $builtInput,
            ];
        }

        return ['ok' => true, 'articles' => $articles];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: mixed, errors?: list<string>}
     */
    private function sampleValue(string $key, array $var, string $ref, int $index): array
    {
        $type = (string) ($var['type'] ?? 'string');
        if (in_array($type, CampaignScriptVariableSchema::SHORTCODE_UNSUPPORTED_TYPES, true)) {
            return ['ok' => false, 'errors' => ['mock_unsupported_type:'.$key.':'.$type]];
        }

        return match ($type) {
            'string', 'text' => $this->sampleString($key, $var, $ref, $index),
            'url' => $this->sampleUrl($key, $var, $index),
            'integer' => $this->sampleInteger($key, $var, $index),
            'number' => $this->sampleNumber($key, $var, $index),
            'boolean' => ['ok' => true, 'value' => ($index % 2) === 1],
            'enum' => $this->sampleEnum($key, $var, $index),
            default => ['ok' => false, 'errors' => ['mock_unsupported_type:'.$key.':'.$type]],
        };
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: string, errors?: list<string>}
     */
    private function sampleString(string $key, array $var, string $ref, int $index): array
    {
        if (is_array($var['enum'] ?? null) && $var['enum'] !== []) {
            return $this->sampleEnum($key, $var, $index);
        }
        $base = 'Mock '.$key.' #'.$index.' ('.$ref.')';
        $min = is_int($var['minLength'] ?? null) ? (int) $var['minLength'] : 0;
        $max = is_int($var['maxLength'] ?? null) ? (int) $var['maxLength'] : null;
        if ($max !== null && $max < 1) {
            return ['ok' => false, 'errors' => ['mock_unsupported_constraint:'.$key]];
        }
        while (mb_strlen($base) < $min) {
            $base .= ' x';
        }
        if ($max !== null && mb_strlen($base) > $max) {
            $base = mb_substr($base, 0, $max);
        }
        $pattern = $var['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            // Cannot safely invent values for arbitrary patterns
            return ['ok' => false, 'errors' => ['mock_unsupported_constraint:'.$key.':pattern']];
        }

        return ['ok' => true, 'value' => $base];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: string, errors?: list<string>}
     */
    private function sampleUrl(string $key, array $var, int $index): array
    {
        unset($var, $key);
        return ['ok' => true, 'value' => 'https://example.test/mock/'.$index];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: int, errors?: list<string>}
     */
    private function sampleInteger(string $key, array $var, int $index): array
    {
        $min = isset($var['min']) && is_numeric($var['min']) ? (int) $var['min'] : 1;
        $max = isset($var['max']) && is_numeric($var['max']) ? (int) $var['max'] : max($min, 100000);
        if ($min > $max) {
            return ['ok' => false, 'errors' => ['mock_unsupported_constraint:'.$key]];
        }
        $value = $min + (($index - 1) % max(1, ($max - $min + 1)));

        return ['ok' => true, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: float|int, errors?: list<string>}
     */
    private function sampleNumber(string $key, array $var, int $index): array
    {
        $min = isset($var['min']) && is_numeric($var['min']) ? (float) $var['min'] : 1.0;
        $max = isset($var['max']) && is_numeric($var['max']) ? (float) $var['max'] : max($min, 1000.0);
        if ($min > $max) {
            return ['ok' => false, 'errors' => ['mock_unsupported_constraint:'.$key]];
        }
        $value = min($max, $min + ($index * 0.5));

        return ['ok' => true, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: mixed, errors?: list<string>}
     */
    private function sampleEnum(string $key, array $var, int $index): array
    {
        $enum = $var['enum'] ?? null;
        if (! is_array($enum) || $enum === []) {
            return ['ok' => false, 'errors' => ['mock_unsupported_type:'.$key.':enum']];
        }
        $values = array_values($enum);
        $value = $values[($index - 1) % count($values)];

        return ['ok' => true, 'value' => $value];
    }
}
