#!/bin/bash

# WordPress Debug Log Sync Script
# Usage: ./sync-debug-log.sh [pull|push|watch|monitor|pause|resume|clear|status]

# Source environment variables
set -a
source .env
set +a

SERVER="${PUNTWORK_SITE_URL#https://}"
REMOTE_USER="$PUNTWORK_FTP_USERNAME"
FTP_PASS="$PUNTWORK_FTP_PASSWORD"
REMOTE_PATH="wp-content/debug.log"
LOCAL_PATH="./wordpress-debug.log"
FTP_HOST="${PUNTWORK_FTP_HOST#ftp://}"

# Pause file for controlling sync
PAUSE_FILE="/tmp/debug_sync_paused"

monitor_debug_log() {
    echo "Monitoring debug.log for bidirectional changes..."
    echo "Press Ctrl+C to stop"

    # Check if server debug log needs clearing at startup
    REMOTE_SIZE=$(curl -s -I -u "$REMOTE_USER:$FTP_PASS" --ftp-ssl -k "ftp://$FTP_HOST/$REMOTE_PATH" | grep "Content-Length" | awk '{print $2}' | tr -d '\r' || echo "0")

    if [ "$REMOTE_SIZE" != "0" ]; then
        echo "Clearing server debug log before starting monitoring..."
        > "$LOCAL_PATH"
        curl -s -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$FTP_HOST/$REMOTE_PATH"
        echo "✓ Server debug log cleared, starting bidirectional sync..."
    else
        echo "✓ Server debug log already empty, starting bidirectional sync..."
    fi

    # Create tracking file for local changes
    LAST_SYNC_FILE="/tmp/debug_sync_state"

    while true; do
        # Get current file sizes
        CURRENT_LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")
        REMOTE_SIZE=$(curl -s -I -u "$REMOTE_USER:$FTP_PASS" --ftp-ssl -k "ftp://$FTP_HOST/$REMOTE_PATH" | grep "Content-Length" | awk '{print $2}' | tr -d '\r' || echo "0")

        # === SERVER → LOCAL DIRECTION ONLY ===
        # Monitor server for new content and pull to local (one-way sync)
        if [ "$REMOTE_SIZE" != "$CURRENT_LOCAL_SIZE" ] && [ -n "$REMOTE_SIZE" ] && [ "$REMOTE_SIZE" != "0" ]; then
            echo "$(date): 🔄 Server change detected, pulling to local..."
            curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE_PATH" -o "$LOCAL_PATH"
            echo "✓ Local updated from server (size: $REMOTE_SIZE bytes)"
        fi

        sleep 5  # Check every 5 seconds for server changes
    done
}

case "$1" in
pull)
    echo "Pulling debug.log from server..."
    curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE_PATH" -o "$LOCAL_PATH"
    echo "✓ Local updated from server"
    ;;
push)
    echo "Pushing debug.log to server..."
    curl -s -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$FTP_HOST/$REMOTE_PATH"
    echo "✓ Server updated from local"
    ;;
monitor)
    monitor_debug_log
    ;;
watch)
    echo "Watch mode - checking every 10 seconds..."
    while true; do
        sleep 10
        MONITOR_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")
        SERVER_SIZE=$(curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE_PATH" | wc -c)
        echo "Local: ${MONITOR_SIZE} bytes, Server: ${SERVER_SIZE} bytes"
    done
    ;;
status)
    LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")
    SERVER_SIZE=$(curl -s -I -u "$REMOTE_USER:$FTP_PASS" --ftp-ssl -k "ftp://$FTP_HOST/$REMOTE_PATH" | grep "Content-Length" | awk '{print $2}' | tr -d '\r' || echo "0")
    echo "WordPress Debug Log Sync Status:"
    echo "Local file ($LOCAL_PATH): ${LOCAL_SIZE} bytes"
    echo "Server file: ${SERVER_SIZE} bytes"
    if [ "$LOCAL_SIZE" = "$SERVER_SIZE" ]; then
        echo "✅ Files are synchronized"
    else
        echo "⚠️  Files are out of sync"
    fi
    ;;
clear)
    echo "Clearing local debug.log..."
    > "$LOCAL_PATH"
    echo "✓ Local file cleared"
    ;;
*)
    echo "Usage: $0 {pull|push|monitor|watch|status|clear}"
    echo "  pull   - Download debug.log from server"
    echo "  push   - Upload debug.log to server"
    echo "  monitor- Bidirectional sync monitoring"
    echo "  watch  - Watch file sizes without syncing"
    echo "  status - Show sync status"
    echo "  clear  - Clear local debug.log"
    exit 1
    ;;
esac
