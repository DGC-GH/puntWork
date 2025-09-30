#!/bin/bash

# Self-Improving Protocol Runner
# This script executes the maintenance protocol with automatic evolution

echo "🚀 Starting Self-Improving Maintenance Protocol"
echo "Evolution Engine: ACTIVE"
echo "========================================"

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed or not in PATH"
    exit 1
fi

# Run the protocol
php run-protocol.php

# Check exit code
if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Protocol completed successfully"
    echo ""
    echo "Next steps:"
    echo "1. Review the changes made"
    echo "2. Test the improvements"
    echo "3. Push to remote if satisfied: git push origin main"
    echo ""
    echo "The protocol will continue to evolve automatically with each execution."
else
    echo ""
    echo "❌ Protocol execution failed"
    echo "Check the output above for details"
    exit 1
fi