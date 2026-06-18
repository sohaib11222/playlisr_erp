#!/usr/bin/env bash
# READ-ONLY: inspect web server access logs for the automated caller that
# POSTs to /product/mass-store. No writes.
set -u
PAT='/product/mass-store'
# Candidate access-log locations (aaPanel/BT, plain nginx, per-vhost).
CANDS=$(ls -1 \
  /www/wwwlogs/*.log \
  /www/wwwlogs/*access*.log \
  /var/log/nginx/*access*.log \
  /www/server/nginx/logs/*.log \
  /www/playlist.nivessa.com/log/*.log \
  2>/dev/null | sort -u)

if [ -z "$CANDS" ]; then
  echo "LOGS\tno access logs found in known locations"
  exit 0
fi
echo "LOGS\tscanning:"; echo "$CANDS" | sed 's/^/  /'

hits() { for f in $CANDS; do grep -hF "$PAT" "$f" 2>/dev/null; done; }

echo "=== last 25 hits to ${PAT} ==="
hits | tail -25

echo "=== count per hour (last lines, by log timestamp) ==="
hits | sed -n 's@.*\[\([0-9]\{2\}/[A-Za-z]\{3\}/[0-9]\{4\}:[0-9]\{2\}\).*@\1@p' | sort | uniq -c | tail -40

echo "=== distinct source IPs ==="
hits | awk '{print $1}' | sort | uniq -c | sort -rn | head

echo "=== distinct user-agents ==="
hits | awk -F'"' '{print $6}' | sort | uniq -c | sort -rn | head
echo "DONE_LOGS"
