#!/bin/bash

# WordPress Debug Log Sync Script
# Usage: ./sync-debug-log.sh [pull|push|watch|monitor]

SERVER="$PUNTWORK_SITE_URL"
REMOTE_USER="$PUNTWORK_FTP_USERNAME"
REMOTE_PATH="/wp-content/debug.log"
LOCAL_PATH="./debug.log"
FTP_PASS="$PUNTWORK_FTP_PASSWORD"

monitor_debug_log() {
    echo "Monitoring debug.log for bidirectional changes..."
    echo "Press Ctrl+C to stop"

    # Create tracking file for local changes
    LAST_SYNC_FILE="/tmp/debug_sync_state"

    while true; do
        # === SERVER → LOCAL DIRECTION ===
        # Get remote file timestamp/size
        REMOTE_INFO=$(curl -s -I --ftp-method EPSV "ftp://$REMOTE_USER:$FTP_PASS@$PUNTWORK_FTP_HOST$REMOTE_PATH" | grep -E "(Content-Length:|Last-Modified:)")
        REMOTE_SIZE=$(echo "$REMOTE_INFO" | grep "Content-Length:" | cut -d' ' -f2 | tr -d '\r')

        # Get local file size
        LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")

        # Pull from server if remote is different and newer
        if [ "$REMOTE_SIZE" != "$LOCAL_SIZE" ] && [ -n "$REMOTE_SIZE" ] && [ "$REMOTE_SIZE" != "0" ]; then
            echo "$(date): 🔄 Server change detected, pulling to local..."
            curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$PUNTWORK_FTP_HOST$REMOTE_PATH" -o "$LOCAL_PATH"
            echo "✓ Local updated from server (size: $REMOTE_SIZE bytes)"
            # Update sync state
            echo "server:$REMOTE_SIZE:$(stat -f%M $LOCAL_PATH 2>/dev/null || echo '0')" > "$LAST_SYNC_FILE"
        fi

        # === LOCAL → SERVER DIRECTION ===
        # Check if local file was modified since last sync
        if [ -f "$LOCAL_PATH" ] && [ -f "$LAST_SYNC_FILE" ]; then
            LAST_LOCAL_MOD=$(cat "$LAST_SYNC_FILE" | cut -d: -f3)
            CURRENT_LOCAL_MOD=$(stat -f%M "$LOCAL_PATH" 2>/dev/null || echo '0')

            if [ "$CURRENT_LOCAL_MOD" != "$LAST_LOCAL_MOD" ]; then
                echo "$(date): 🔄 Local change detected, pushing to server..."
                curl -s -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$PUNTWORK_FTP_HOST$REMOTE_PATH"
                echo "✓ Server updated from local"

                # Update sync state with current local mod time
                CURRENT_LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")
                echo "local:$CURRENT_LOCAL_SIZE:$CURRENT_LOCAL_MOD" > "$LAST_SYNC_FILE"
            fi
        elif [ -f "$LOCAL_PATH" ]; then
            # Initialize sync state if it doesn't exist
            CURRENT_LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")
            CURRENT_LOCAL_MOD=$(stat -f%M "$LOCAL_PATH" 2>/dev/null || echo '0')
            echo "local:$CURRENT_LOCAL_SIZE:$CURRENT_LOCAL_MOD" > "$LAST_SYNC_FILE"
        fi

        sleep 5  # Check every 5 seconds for bidirectional sync
    done
}

case "$1" in
    "pull")
        echo "Pulling debug.log from server..."
        curl -u "$REMOTE_USER:$FTP_PASS" "ftp://$PUNTWORK_FTP_HOST$REMOTE_PATH" -o "$LOCAL_PATH"
        echo "Debug log updated from server"
        ;;
    "push")
        echo "Pushing debug.log to server..."
        curl -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$PUNTWORK_FTP_HOST$REMOTE_PATH"
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
    *)
        echo "Usage: $0 {pull|push|watch|monitor}"
        echo "  pull    - Download debug.log from server once"
        echo "  push    - Upload debug.log to server"
        echo "  watch   - Watch for local changes and sync to server"
        echo "  monitor - Bidirectional sync: server ↔ local automatically"
        exit 1
        ;;
esac
