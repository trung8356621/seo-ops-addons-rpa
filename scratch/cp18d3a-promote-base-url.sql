-- CP18D.3A — Owner-run, non-destructive Base URL promote
-- Connection: omi_social_automation / table: sa_sites
-- Do NOT run automatically. Do NOT delete test_base_url yet.
-- Do NOT overwrite non-empty base_url.

-- 1) READ-ONLY inventory of rows needing promote
SELECT id, `key`, name, base_url_mode, base_url, test_base_url
FROM sa_sites
WHERE (base_url IS NULL OR TRIM(base_url) = '')
  AND test_base_url IS NOT NULL
  AND TRIM(test_base_url) <> '';

-- 2) Preview mayhopphat specifically
SELECT id, `key`, base_url, test_base_url
FROM sa_sites
WHERE LOWER(`key`) = 'mayhopphat';

-- 3) SAFE PROMOTE (Owner approval required)
-- Copies legacy test_base_url into canonical base_url only when base_url is empty.
UPDATE sa_sites
SET base_url = TRIM(TRAILING '/' FROM TRIM(test_base_url))
WHERE LOWER(`key`) = 'mayhopphat'
  AND (base_url IS NULL OR TRIM(base_url) = '')
  AND test_base_url IS NOT NULL
  AND TRIM(test_base_url) <> '';

-- 4) Optional bulk promote for all empty base_url rows (Owner approval)
-- UPDATE sa_sites
-- SET base_url = TRIM(TRAILING '/' FROM TRIM(test_base_url))
-- WHERE (base_url IS NULL OR TRIM(base_url) = '')
--   AND test_base_url IS NOT NULL
--   AND TRIM(test_base_url) <> '';

-- 5) VERIFY
SELECT id, `key`, base_url, test_base_url
FROM sa_sites
WHERE LOWER(`key`) = 'mayhopphat';
