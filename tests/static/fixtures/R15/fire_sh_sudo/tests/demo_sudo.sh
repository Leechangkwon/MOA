#!/bin/bash
# 가짜 견본: sudo 뒤의 VAR=값 도 sudo 의 인자다(기록에 남는다)
TOKEN=$(jq -r .token /tmp/demo-response.json)
sudo -n -u app env API_TOKEN="$TOKEN" /usr/local/bin/demo-tool --sync
