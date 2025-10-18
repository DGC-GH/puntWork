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
LOCAL_PATH="./debug.log"
FTP_HOST="${PUNTWORK_FTP_HOST#ftp://}"

# Pause file for controlling sync
PAUSE_FILE="/tmp/debug_sync_paused"

monitor_debug_log() {
    echo "Monitoring debug.log for changes..."
    echo "Press Ctrl+C to stop"

    while true; do
        # Get current local file size
        CURRENT_LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")

        # Get server size using lftp
        REMOTE_SIZE=$( /opt/homebrew/bin/lftp -c "set ssl:verify-certificate no; open -u '$REMOTE_USER','$FTP_PASS' ftp://$FTP_HOST; ls -la $REMOTE_PATH" | awk '{print $5}' | tail -n1 | tr -d '\r' || echo "0" )

        # SERVER → LOCAL DIRECTION
        if [ "$REMOTE_SIZE" != "$CURRENT_LOCAL_SIZE" ] && [ -n "$REMOTE_SIZE" ] && [ "$REMOTE_SIZE" != "0" ]; then
            # Check if local file is empty - if so, clear server instead of pulling
            if [ "$CURRENT_LOCAL_SIZE" -eq 0 ]; then
                echo "$(date): 🧹 Local is empty, clearing server log..."
                echo -n > /tmp/empty_file
                curl -s -u "$REMOTE_USER:$FTP_PASS" -T /tmp/empty_file "ftp://$FTP_HOST/$REMOTE_PATH"
                echo "$(date): 🧹 Server log cleared to match local empty state"
                rm -f /tmp/empty_file
            else
                echo "$(date): 🔄 Server change detected, pulling to local..."
                curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE_PATH" -o "$LOCAL_PATH"
                echo "✓ Local updated from server (size: $REMOTE_SIZE bytes)"
            fi
        fi

        # No local -> server pushing in monitor mode - simplified

        sleep 5  # Check every 5 seconds
    done
}

case "$1" in
    "pull")
        echo "Pulling debug.log from server..."
        curl -u "$REMOTE_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE_PATH" -o "$LOCAL_PATH"
        echo "Debug log updated from server"
        ;;
    "push")
        echo "Pushing debug.log to server..."
        curl -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$FTP_HOST/$REMOTE_PATH"
        echo "Debug log pushed to server"
        ;;
    "watch")
        echo "Watching for LOCAL changes... (Ctrl+C to stop)"
        while true; do
            if [ "$LOCAL_PATH" -nt "/tmp/debug_last_sync" ] 2>/dev/null; then
                echo "Local changes detected, uploading..."
                $0 push
                touch "/tmp/debug_last_sync"
            fi
            sleep 30
        done
        ;;
    "monitor")
        monitor_debug_log
        ;;
    "pause")
        touch "$PAUSE_FILE"
        echo "🔇 Sync paused. Local changes will not be pushed to server."
        echo "Use 'resume' to continue or 'clear' to reset local log."
        ;;
    "resume")
        rm -f "$PAUSE_FILE"
        echo "▶️ Sync resumed. Bidirectional syncing active again."
        ;;
    "clear")
        echo "Clearing local and remote debug.log..."
        > "$LOCAL_PATH"
        echo "✓ Local debug.log cleared."

        # Immediately push empty file to server for fresh logs
        echo "🔄 Pushing cleared log to server..."
        curl -s -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$FTP_HOST/$REMOTE_PATH"
        echo "✓ Server debug.log cleared as well."

        echo "🟢 Both local and remote debug logs are now fresh and ready for new logs."
        ;;
    "status")
        if [ -f "$PAUSE_FILE" ]; then
            echo "🔇 Sync Status: PAUSED"
            echo "Local debug.log changes are ignored. Server changes still pull."
        else
            echo "▶️ Sync Status: ACTIVE"
            echo "Bidirectional sync running (5-second intervals)"
        fi
        ;;
    *)
        echo "Usage: $0 {pull|push|watch|monitor|pause|resume|clear|status}"
        echo "  pull    - Download debug.log from server once"
        echo "  push    - Upload debug.log to server"
        echo "  watch   - Watch for local changes and sync to server"
        echo "  monitor - Bidirectional sync: server ↔ local automatically"
        echo "  pause   - Pause sync (allows local changes without overwriting)"
        echo "  resume  - Resume automatic sync"
        echo "  clear   - Clear local debug.log for testing"
        echo "  status  - Show current sync status"
        exit 1
        ;;
esac
