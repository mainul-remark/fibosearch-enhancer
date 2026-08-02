# Graph Report - E:\laragon\www\herlanlive5\wp-content\plugins\fibosearch-enhancer  (2026-07-22)

## Corpus Check
- Corpus is ~36,481 words - fits in a single context window. You may not need a graph.

## Summary
- 247 nodes · 316 edges · 21 communities (6 shown, 15 thin omitted)
- Extraction: 83% EXTRACTED · 17% INFERRED · 0% AMBIGUOUS · INFERRED: 55 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Multi-Modal Search Strategies
- Plugin Core and Admin Settings
- Behavior Tracking Design Docs
- Admin Dashboard and AJAX API
- AI Query Enhancement Design
- Visitor Behavior Event Tracking
- Behavior Rollup and Scheduling
- AI Query Enhancement Engine
- Dynamic Priority Tier Mapping
- AI LLM API Client
- Competitor Keyword Fallback
- Zero-Result Search Logger
- Category and Soundex Search
- Discount Intent Fallback
- Variation and SKU Search
- Admin UI Components
- Custom Field Query Search
- Field Weight Scoring
- AI Settings Integration Refs
- Score Boost Concept

## God Nodes (most connected - your core abstractions)
1. `FSE_Helpers` - 32 edges
2. `fse_get_option()` - 27 edges
3. `FSE_Admin` - 15 edges
4. `FSE_BehaviorTracker` - 14 edges
5. `FSE_AIQueryEnhancer` - 11 edges
6. `FSE_PriorityMapping` - 10 edges
7. `FSE_AI_Client` - 9 edges
8. `FSE_BehaviorRollup` - 7 edges
9. `FSE_CompetitorFallback` - 7 edges
10. `FSE_CustomTaxonomySearch` - 7 edges

## Surprising Connections (you probably didn't know these)
- `fse_opt()` --calls--> `fse_get_option()`  [INFERRED]
  admin/views/page-settings.php → fibosearch-enhancer.php
- `Fail-Open Error Handling Principle` --semantically_similar_to--> `Search Behavior Tracking Design Spec`  [INFERRED] [semantically similar]
  docs/superpowers/plans/2026-07-14-ai-query-enhancement.md → docs/superpowers/specs/2026-07-18-search-behavior-tracking-design.md
- `fse_init()` --calls--> `FSE_BehaviorRollup`  [INFERRED]
  fibosearch-enhancer.php → includes/class-behavior-rollup.php
- `fse_init()` --calls--> `FSE_BehaviorSchema`  [INFERRED]
  fibosearch-enhancer.php → includes/class-behavior-schema.php
- `Hallucination Guard (category validation against real terms)` --semantically_similar_to--> `Relevance Filter (keyword-in-title/content guard)`  [INFERRED] [semantically similar]
  docs/superpowers/specs/2026-07-14-ai-query-enhancement-design.md → docs/superpowers/specs/2026-07-18-dynamic-priority-mapping-design.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **ML for Search Initiative Phase Roadmap (Phases 1-5)** — docs_superpowers_specs_2026_07_18_search_behavior_tracking_design_ml_for_search_phase1, docs_superpowers_specs_2026_07_18_dynamic_priority_mapping_design_ml_for_search_phase2, docs_superpowers_specs_2026_07_18_search_behavior_tracking_design_ml_for_search_phase3, docs_superpowers_specs_2026_07_18_search_behavior_tracking_design_ml_for_search_phase4, docs_superpowers_specs_2026_07_18_search_behavior_tracking_design_ml_for_search_phase5 [EXTRACTED 1.00]
- **FiboSearch Hook Integration Flow (phrase → search_or → score)** — docs_superpowers_specs_2026_07_14_ai_query_enhancement_design_fibosearch_hook_dgwt_phrase, docs_superpowers_specs_2026_07_14_ai_query_enhancement_design_fibosearch_hook_search_or, docs_superpowers_specs_2026_07_14_ai_query_enhancement_design_fibosearch_hook_score, docs_superpowers_plans_2026_07_14_ai_query_enhancement_fse_ai_query_enhancer [EXTRACTED 1.00]
- **Search Behavior Tracking Write Pipeline (tracker → events table → rollup → stats table)** — docs_superpowers_plans_2026_07_18_search_behavior_tracking_fse_behavior_tracker, docs_superpowers_plans_2026_07_18_search_behavior_tracking_fse_search_events_table, docs_superpowers_plans_2026_07_18_search_behavior_tracking_fse_behavior_rollup, docs_superpowers_plans_2026_07_18_search_behavior_tracking_fse_search_stats_table [EXTRACTED 1.00]

## Communities (21 total, 15 thin omitted)

### Community 0 - "Multi-Modal Search Strategies"
Cohesion: 0.07
Nodes (5): FSE_AttributeSearch, FSE_CustomTaxonomySearch, FSE_Helpers, FSE_SynonymSearch, FSE_TagSearch

### Community 1 - "Plugin Core and Admin Settings"
Cohesion: 0.06
Nodes (10): fse_opt(), fse_enqueue_behavior_tracker(), fse_get_option(), FSE_BanglaTranslation, FSE_FillerWordStrip, FSE_FuzzySearch, FSE_IngredientFieldBoost, FSE_ScoreBoost (+2 more)

### Community 2 - "Behavior Tracking Design Docs"
Cohesion: 0.11
Nodes (24): behavior-tracker.js (client-side capture), FSE_BehaviorRollup, FSE_BehaviorSchema, FSE_BehaviorTracker, fse_search_events DB Table, fse_search_stats DB Table, fse_track_event AJAX Beacon Endpoint, fse_vid Visitor Cookie (+16 more)

### Community 4 - "AI Query Enhancement Design"
Cohesion: 0.12
Nodes (17): AI Category Boost (AI_CATEGORY_BOOST = 25), AI Query Enhancement Implementation Plan, curl_multi Vendor Racing Pattern, Fail-Open Error Handling Principle, FSE_AI_Client, FSE_AIQueryEnhancer, FSE_Helpers, FSE_SynonymSearch (+9 more)

### Community 6 - "Behavior Rollup and Scheduling"
Cohesion: 0.17
Nodes (3): fse_init(), FSE_BehaviorRollup, FSE_BehaviorSchema

## Knowledge Gaps
- **14 isolated node(s):** `AI Category Boost (AI_CATEGORY_BOOST = 25)`, `FSE_SynonymSearch`, `FSE_ScoreBoost`, `FSE_Helpers`, `FSE_Admin (sanitize_settings)` (+9 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **15 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `fse_get_option()` connect `Plugin Core and Admin Settings` to `Multi-Modal Search Strategies`, `Admin Dashboard and AJAX API`, `AI Query Enhancement Engine`, `Dynamic Priority Tier Mapping`, `AI LLM API Client`, `Competitor Keyword Fallback`, `Zero-Result Search Logger`, `Category and Soundex Search`, `Discount Intent Fallback`, `Variation and SKU Search`, `Custom Field Query Search`, `Field Weight Scoring`?**
  _High betweenness centrality (0.491) - this node is a cross-community bridge._
- **Why does `FSE_Helpers` connect `Multi-Modal Search Strategies` to `Plugin Core and Admin Settings`, `AI Query Enhancement Engine`, `Dynamic Priority Tier Mapping`, `Competitor Keyword Fallback`, `Category and Soundex Search`, `Variation and SKU Search`?**
  _High betweenness centrality (0.130) - this node is a cross-community bridge._
- **Why does `FSE_BehaviorTracker` connect `Visitor Behavior Event Tracking` to `Plugin Core and Admin Settings`?**
  _High betweenness centrality (0.086) - this node is a cross-community bridge._
- **Are the 22 inferred relationships involving `FSE_Helpers` (e.g. with `.add_conditions()` and `.inject_products()`) actually correct?**
  _`FSE_Helpers` has 22 INFERRED edges - model-reasoned connections that need verification._
- **Are the 25 inferred relationships involving `fse_get_option()` (e.g. with `fse_opt()` and `.fetch()`) actually correct?**
  _`fse_get_option()` has 25 INFERRED edges - model-reasoned connections that need verification._
- **What connects `AI Category Boost (AI_CATEGORY_BOOST = 25)`, `FSE_SynonymSearch`, `FSE_ScoreBoost` to the rest of the system?**
  _14 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Multi-Modal Search Strategies` be split into smaller, more focused modules?**
  _Cohesion score 0.06906906906906907 - nodes in this community are weakly interconnected._