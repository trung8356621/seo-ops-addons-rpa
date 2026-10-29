#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
F1="$SA/src/Models/SaScriptRequest.php"
F2="$SA/src/Http/Controllers/ActionRegistry/ActionRegistryController.php"

echo "===VPS_SHA256==="
for f in "$F1" "$F2"; do
  if [ -f "$f" ]; then
    sha256sum "$f"
    stat -c 'mtime=%y size=%s' "$f"
  else
    echo "MISSING $f"
  fi
done

echo
echo "===openCreateRequestStatuses==="
grep -n "openCreateRequestStatuses" "$F1" "$F2" 2>/dev/null || echo "NO_MATCH_IN_EITHER"

echo
echo "===STATUS_IN_PROGRESS in storeTag scope==="
# Extract storeTag() body roughly and search STATUS_IN_PROGRESS
python3 - <<'PY'
from pathlib import Path
p = Path("/www/wwwroot/seo-ops-addons/social-automation/src/Http/Controllers/ActionRegistry/ActionRegistryController.php")
text = p.read_text(encoding="utf-8", errors="replace")
idx = text.find("function storeTag")
if idx < 0:
    print("storeTag_NOT_FOUND")
else:
    # find next function after storeTag at same indent level roughly
    rest = text[idx:]
    # cut at next "\n    public function" or "\n    protected function" or "\n    private function" after start
    import re
    m = re.search(r"\n    (public|protected|private) function ", rest[20:])
    body = rest if not m else rest[:20+m.start()]
    hits = [(i+1, line) for i, line in enumerate(body.splitlines()) if "STATUS_IN_PROGRESS" in line]
    print(f"storeTag_bytes={len(body)}")
    if hits:
        for n, line in hits:
            print(f"HIT_REL_LINE_{n}: {line.strip()}")
    else:
        print("STATUS_IN_PROGRESS_IN_storeTag=NO")
# also whole-file count for context
all_hits = [i+1 for i,l in enumerate(text.splitlines()) if "STATUS_IN_PROGRESS" in l]
print(f"STATUS_IN_PROGRESS_FILE_LINES={all_hits or 'NONE'}")
open_hits = [i+1 for i,l in enumerate(text.splitlines()) if "openCreateRequestStatuses" in l]
print(f"openCreateRequestStatuses_FILE_LINES={open_hits or 'NONE'}")
PY
