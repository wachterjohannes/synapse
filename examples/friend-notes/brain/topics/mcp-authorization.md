---
type: Topic
title: "MCP authorization in practice"
description: "How an MCP server decides who may call its tools."
tags: [mcp, oauth]
status: stable
generated:
  by: human:johannes
  at: 2026-10-27T08:00:00Z
---

# MCP authorization in practice

An MCP server over HTTP is an OAuth resource server. It does not issue tokens, it checks them. The client finds the authorization server through the protected resource metadata of the server.

Since the 2026-07-28 revision of the specification, Client ID Metadata Documents (CIMD) are the recommended way for a client to identify itself. Dynamic client registration is deprecated and stays for backwards compatibility.

A server that only wants to read a token can ask the authorization server through token introspection, or verify a signed JWT locally.
