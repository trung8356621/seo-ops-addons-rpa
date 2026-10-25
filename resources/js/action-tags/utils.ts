import { PathInput } from './types';

export function parseInputsFromPattern(pattern: string): PathInput[] {
  if (!pattern) return [];
  const matches = pattern.matchAll(/\{([a-zA-Z0-9_]+)\}/g);
  const keys = Array.from(new Set(Array.from(matches, (m) => m[1])));
  return keys.map((key) => ({
    key,
    type: 'String',
    required: true,
  }));
}

export function resolveUrl(baseUrl: string, pattern: string, inputValues: Record<string, string>): {
  url: string;
  missing: string[];
  valid: boolean;
} {
  const normBase = (baseUrl || '').trim().replace(/\/+$/, '');
  let normPattern = (pattern || '').trim();
  if (!normPattern) normPattern = '/';
  if (!normPattern.startsWith('/')) normPattern = '/' + normPattern;

  const inputs = parseInputsFromPattern(normPattern);
  const missing: string[] = [];
  let resolved = normPattern;

  for (const input of inputs) {
    const val = inputValues[input.key]?.trim() ?? '';
    if (!val) {
      missing.push(input.key);
    } else {
      resolved = resolved.replace(new RegExp(`\\{${input.key}\\}`, 'g'), encodeURIComponent(val));
    }
  }

  const fullUrl = normBase + (resolved === '/' ? '' : resolved);
  return {
    url: fullUrl || normBase || '/',
    missing,
    valid: missing.length === 0,
  };
}
