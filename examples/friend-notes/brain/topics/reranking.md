---
type: Topic
title: "Why rerank merged results"
description: "Scores from different sources cannot be compared."
tags: [rag, rerank]
status: stable
generated:
  by: human:johannes
  at: 2026-10-27T08:00:00Z
---

# Why rerank merged results

Two search systems score on their own scales. A cosine distance from one store and a rank score from another say nothing about each other. A reranker reads the question and each passage and gives one comparable relevance score, so merged results can be sorted honestly.
