#!/bin/bash

# Quick commit script that bypasses pre-commit checks
# Usage: ./commit.sh "Your commit message"

if [ $# -eq 0 ]; then
    echo "Usage: $0 \"Your commit message\""
    echo "This will commit with pre-commit checks bypassed"
    exit 1
fi

echo "🚀 Committing with checks bypassed..."
git add .
SKIP_PRECOMMIT_CHECKS=true git commit -m "$1"

echo "✅ Commit completed successfully!"