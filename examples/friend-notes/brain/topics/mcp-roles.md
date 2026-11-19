---
type: Topic
title: "Roles for MCP tools"
description: "Mapping OAuth scopes to what a tool may do."
tags: [mcp, security, scopes]
status: stable
generated:
  by: human:johannes
  at: 2026-10-27T08:00:00Z
---

# Roles for MCP tools

Give every tool the least scope it needs. Reading and writing are separate scopes. The server turns scopes into roles and checks the role inside the tool, so a token that may only read gets a clear error when it tries to write.

Hide nothing by accident: a client that lists the tools should see that write tools exist, and learn from the error why they fail.
