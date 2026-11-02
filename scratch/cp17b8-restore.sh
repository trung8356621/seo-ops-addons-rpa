#!/bin/bash
set -euo pipefail
TMP='/www/wwwroot/seo-ops-addons/social-automation/src/Support/.SocialAutomationServiceHealth.php.cp17b8.tmp'
TGT='/www/wwwroot/seo-ops-addons/social-automation/src/Support/SocialAutomationServiceHealth.php'
EXPECTED='7674128ceed5b9a370bead4b1dc427123c65ca6774e530fbb328d59bd3de882d'
EXPECTED_SIZE='2413'
CUR_SIZE=$(wc -c < "$TGT" | tr -d ' ')
echo "CUR_SIZE=$CUR_SIZE"
if [ "$CUR_SIZE" != "0" ]; then
  echo "CONFLICT: target size=$CUR_SIZE"
  rm -f "$TMP"
  exit 3
fi
TMP_SIZE=$(wc -c < "$TMP" | tr -d ' ')
TMP_HASH=$(sha256sum "$TMP" | cut -d' ' -f1)
echo "TMP size=$TMP_SIZE hash=$TMP_HASH"
if [ "$TMP_SIZE" = "0" ]; then echo TMP_EMPTY; rm -f "$TMP"; exit 4; fi
if [ "$TMP_HASH" != "$EXPECTED" ]; then echo "TMP_HASH_MISMATCH expected=$EXPECTED got=$TMP_HASH"; rm -f "$TMP"; exit 5; fi
if [ "$TMP_SIZE" != "$EXPECTED_SIZE" ]; then echo TMP_SIZE_MISMATCH; rm -f "$TMP"; exit 6; fi
mv -f "$TMP" "$TGT"
FINAL_SIZE=$(wc -c < "$TGT" | tr -d ' ')
FINAL_HASH=$(sha256sum "$TGT" | cut -d' ' -f1)
echo "FINAL size=$FINAL_SIZE hash=$FINAL_HASH"
if [ "$FINAL_HASH" != "$EXPECTED" ]; then echo FINAL_HASH_MISMATCH; exit 7; fi
stat -c 'mtime=%y mode=%a owner=%U:%G' "$TGT"
/www/server/php/83/bin/php -l "$TGT"
/www/server/php/83/bin/php -r 'require "/www/wwwroot/seo-ops-client/vendor/autoload.php"; echo "class_exists=".(class_exists("Omnichannel\\Addons\\SocialAutomation\\Support\\SocialAutomationServiceHealth", true)?"1":"0").PHP_EOL;'
