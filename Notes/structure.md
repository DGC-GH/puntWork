# Repository Structure

## Overview
Standard WP plugin layout with Notes for docs.

## Current Tree State.
.
├── .DS_Store
├── Notes/
│   ├── development-roadmap.md
│   ├── requirements.md
│   ├── rules.md
│   └── structure.md  (self)
└── job-import/
├── assets/
│   ├── css/
│   └── js/
├── includes/
│   ├── admin.php
│   ├── ajax.php
│   └── core.php
├── job-import.php
├── logs/
│   └── import.log
└── snippets/  (Phase 1 source; archive post-refactor)
        ├── 1 - Core Structure and Logic.php
        ├── 1.1 - Mappings and Constants.php
        ├── 1.2 - Utility Helpers.php
        ├── 1.3 - Scheduling and Triggers.php
        ├── 1.4 - Heartbeat Control.php
        ├── 1.5 - Heartbeat Control.php (possible duplicate or variant of 1.4)
        ├── 1.6 - Item Cleaning.php
        ├── 1.7 - Item Inference.php
        ├── 1.8 - Download Feed.php
        ├── 1.9 - Process XML Batch.php
        ├── 2 - Admin Page HTML.php
        ├── 2.1 - Gzip File.php
        ├── 2.2 - Combine JSONL.php
        ├── 2.3 - Import Batch.php
        ├── 2.4 - Handle Duplicates.php
        ├── 2.5 - Process Batch Items.php
        ├── 3 - Enqueue Scripts and JS.php
        ├── 4 - AJAX Handlers.php
        ├── 5 - Shortcode.php
        └── 6 - Admin Menu.php

*(Auto-updated via API: https://api.github.com/repos/DGC-GH/puntWork/contents)*

## New: ## Evolution Log
- v0.1 (Sep 2025): Baseline + roadmap.
- Post-Phase 1: Remove snippets/; add classes/.