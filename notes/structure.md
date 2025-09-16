# Repository Structure

## Overview
Standard WP plugin layout with Notes for docs.

## Current Tree State.
.
├── .DS_Store
├── snippets/  (Phase 1 source; archive post-refactor)
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


*(Auto-updated via API: https://api.github.com/repos/DGC-GH/puntWork/contents)*


## Snippets Code
compare the code in "snippets" folder of my repo with code in "



## Snippets Code

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1%20-%20Core%20Structure%20and%20Logic.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.1%20-%20Mappings%20and%20Constants.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.2%20-%20Utility%20Helpers.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.3%20-%20Scheduling%20and%20Triggers.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.4%20-%20Heartbeat%20Control.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.5%20-%20Heartbeat%20Control.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.6%20-%20Item%20Cleaning.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.7%20-%20Item%20Inference.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.8%20-%20Download%20Feed.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/1.9%20-%20Process%20XML%20Batch.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/2%20-%20Admin%20Page%20HTML.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/2.1%20-%20Gzip%20File.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/2.2%20-%20Combine%20JSONL.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/2.3%20-%20Import%20Batch.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/2.4%20-%20Handle%20Duplicates.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/2.5%20-%20Process%20Batch%20Items.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/3%20-%20Enqueue%20Scripts%20and%20JS.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/4%20-%20AJAX%20Handlers.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/5%20-%20Shortcode.php

https://raw.githubusercontent.com/DGC-GH/puntWork/refs/heads/main/snippets/6%20-%20Admin%20Menu.php



## New: ## Evolution Log
- v0.1 (Sep 2025): Baseline + roadmap.
- Post-Phase 1: Remove snippets/; add classes/.
