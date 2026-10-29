#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
F1="$SA/src/Models/SaScriptRequest.php"
F2="$SA/src/Http/Controllers/ActionRegistry/ActionRegistryController.php"

echo "===VPS_SHA256==="
sha256sum "$F1" "$F2"

echo
echo "===HELPER==="
grep -n "openCreateRequestStatuses" "$F1" "$F2" || true

echo
echo "===storeTag_BAD_CONSTANTS==="
python3 - <<'PY'
from pathlib import Path
import re
text = Path("/www/wwwroot/seo-ops-addons/social-automation/src/Http/Controllers/ActionRegistry/ActionRegistryController.php").read_text(encoding="utf-8", errors="replace")
m = re.search(r"function\s+storeTag\b([\s\S]*?)(?=\n    public function |\n    protected function |\n    private function |\n}\s*$)", text)
if not m:
    print("storeTag_NOT_FOUND")
else:
    body = m.group(1)
    print("has_openCreateRequestStatuses=", "openCreateRequestStatuses()" in body)
    print("has_STATUS_IN_PROGRESS=", "STATUS_IN_PROGRESS" in body)
    print("has_STATUS_COMPLETED=", "STATUS_COMPLETED" in body)
model = Path("/www/wwwroot/seo-ops-addons/social-automation/src/Models/SaScriptRequest.php").read_text(encoding="utf-8", errors="replace")
print("model_has_helper=", "function openCreateRequestStatuses" in model)
print("model_has_STATUS_IN_PROGRESS=", "STATUS_IN_PROGRESS" in model)
print("model_has_STATUS_COMPLETED=", "STATUS_COMPLETED" in model)
PY
