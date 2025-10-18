#!/bin/bash

# Setup automatic debug log monitoring startup

echo "Setting up automatic debug log monitoring..."

# Install launch agent
PLIST_SRC="./com.debugmonitor.plist"
PLIST_DEST="$HOME/Library/LaunchAgents/com.debugmonitor.plist"

if [ -f "$PLIST_SRC" ]; then
    cp "$PLIST_SRC" "$PLIST_DEST"
    echo "Launch agent copied to $PLIST_DEST"
else
    echo "Error: Launch agent file not found!"
    exit 1
fi

# Load the launch agent
launchctl unload "$PLIST_DEST" 2>/dev/null
launchctl load "$PLIST_DEST"

if [ $? -eq 0 ]; then
    echo "✓ Launch agent loaded successfully"
    echo "✓ Monitoring will start automatically on next login"
    echo "✓ Monitoring will restart automatically if it stops"
else
    echo "✗ Failed to load launch agent"
    exit 1
fi

# Test immediate start
echo "Starting monitoring now..."
./start-monitoring.sh start

echo ""
echo "Setup complete! Monitoring will:"
echo "- Start automatically when you log in"
echo "- Run continuously in the background"
echo "- Restart automatically if it crashes"
echo ""
echo "Commands:"
echo "  ./start-monitoring.sh status   - Check status"
echo "  ./start-monitoring.sh stop     - Stop monitoring"
echo "  ./start-monitoring.sh restart  - Restart monitoring"
