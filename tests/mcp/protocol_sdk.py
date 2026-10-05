#!/usr/bin/env python3
"""Validate actual PHP-produced tool/wire output with the installed MCP SDK (no server/network).
Exit 77 explicitly means not executed: PHP or the MCP SDK is unavailable.
"""
import json
from pathlib import Path
import shutil
import subprocess
import sys
try:
    from mcp.types import Tool, JSONRPCResponse, InitializeResult, CallToolResult
except ImportError:
    print('SKIP: installed MCP SDK required'); sys.exit(77)
if not shutil.which('php'):
    print('SKIP: PHP required to produce real dispatcher/schema fixtures'); sys.exit(77)
root = Path(__file__).resolve().parents[2]
fixtures = json.loads(subprocess.check_output(['php', str(root / 'tests/mcp/protocol_unit.php'), '--fixtures'], text=True))
for definition in fixtures['tools']:
    Tool.model_validate(definition)
for key in ('initialize', 'ping', 'whoami'):
    JSONRPCResponse.model_validate(fixtures[key])
InitializeResult.model_validate(fixtures['initialize']['result'])
CallToolResult.model_validate(fixtures['whoami']['result'])
print(f'MCP_SDK_CONTRACT_PASS {len(fixtures["tools"])} tools, 3 wire responses, initialize + tool result')
