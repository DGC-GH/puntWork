#!/bin/bash

# WordPress Debug Log Sync Script
# Usage: ./sync-debug-log.sh [pull|push|watch|monitor|pause|resume|clear|status]

SERVER="belgiumjobs.work"
REMOTE_USER="u164580062.belgiumjobs.work"
REMOTE_PATH="/wp-content/debug.log"
LOCAL_PATH="./debug.log"
FTP_PASS="Ftpbjw2105."

# Pause file for controlling sync
PAUSE_FILE="/tmp/debug_sync_paused"

monitor_debug_log() {
    echo "Monitoring debug.log for bidirectional changes (auto-pause when editing locally)..."
    echo "Press Ctrl+C to stop"

    # Create tracking file for local changes
    LAST_SYNC_FILE="/tmp/debug_sync_state"
    DEV_MODE_FILE="/tmp/debug_dev_mode"

    while true; do
        # === DEVELOPMENT MODE DETECTION ===
        # Auto-detect when user is editing locally (small files or recent clears)
        CURRENT_LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")

        # Detect development mode conditions:
        # 1. Local file is much smaller than last known server sync
        # 2. Local file is empty (just cleared)
        # 3. Manual dev mode flag exists
        if [ -f "$LAST_SYNC_FILE" ]; then
            LAST_SERVER_SIZE=$(cat "$LAST_SYNC_FILE" | head -n1 | cut -d: -f2 2>/dev/null || echo "0")
            # Enter dev mode if: manually flagged, empty file, or much smaller than server
            if [ -f "$DEV_MODE_FILE" ] || [ "$CURRENT_LOCAL_SIZE" -eq 0 ] || [ "$LAST_SERVER_SIZE" -gt 1000 -a "$CURRENT_LOCAL_SIZE" -lt 100 ]; then
                touch "$DEV_MODE_FILE"
                echo "$(date): 🛠️ DEV MODE: Local editing detected, pausing server pushes for testing"
            fi
        fi

        # === SERVER → LOCAL DIRECTION ===
        # Get remote file timestamp/size
        REMOTE_INFO=$(curl -s -I --ftp-method EPSV "ftp://$REMOTE_USER:$FTP_PASS@153.92.216.191$REMOTE_PATH" | grep -E "(Content-Length:|Last-Modified:)")
        REMOTE_SIZE=$(echo "$REMOTE_INFO" | grep "Content-Length:" | cut -d' ' -f2 | tr -d '\r')

        # Get local file size
        LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")

        # Pull from server if remote is different and newer (but don't overwrite if in dev mode and local was manually set)
        if [ "$REMOTE_SIZE" != "$LOCAL_SIZE" ] && [ -n "$REMOTE_SIZE" ] && [ "$REMOTE_SIZE" != "0" ]; then
            # If not in dev mode OR server is MUCH larger (error logs), allow pull
            if [ ! -f "$DEV_MODE_FILE" ] || [ "$REMOTE_SIZE" -gt "$((LOCAL_SIZE + 1000))" ]; then
                echo "$(date): 🔄 Server change detected, pulling to local..."
                curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://153.92.216.191$REMOTE_PATH" -o "$LOCAL_PATH"
                echo "✓ Local updated from server (size: $REMOTE_SIZE bytes)"
                # Update sync state
                echo "server:$REMOTE_SIZE:$(stat -f%M $LOCAL_PATH 2>/dev/null || echo '0')" > "$LAST_SYNC_FILE"

                # Exit dev mode if it was server-triggered pull
                if [ ! -f "$DEV_MODE_FILE" ]; then
                    rm -f "$DEV_MODE_FILE"
                fi
            else
                echo "$(date): 🔇 DEV MODE: Skipping server pull to allow local testing"
            fi
        fi

        # === LOCAL → SERVER DIRECTION ===
        # Check if local file was modified since last sync
        if [ ! -f "$DEV_MODE_FILE" ] && [ -f "$LOCAL_PATH" ] && [ -f "$LAST_SYNC_FILE" ]; then
            LAST_LOCAL_MOD=$(cat "$LAST_SYNC_FILE" | cut -d: -f3 2>/dev/null)
            CURRENT_LOCAL_MOD=$(stat -f%M "$LOCAL_PATH" 2>/dev/null || echo '0')

            if [ "$CURRENT_LOCAL_MOD" != "$LAST_LOCAL_MOD" ] && [ "$CURRENT_LOCAL_SIZE" -gt 10 ]; then
                echo "$(date): 🔄 Local change detected, pushing to server..."
                curl -s -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://153.92.216.191$REMOTE_PATH"
                echo "✓ Server updated from local"

                # Update sync state with current local mod time
                echo "local:$CURRENT_LOCAL_SIZE:$CURRENT_LOCAL_MOD" > "$LAST_SYNC_FILE"

                # Exit dev mode after successful push
                if [ -f "$DEV_MODE_FILE" ] && [ "$CURRENT_LOCAL_SIZE" -gt 500 ]; then
                    rm -f "$DEV_MODE_FILE"
                    echo "$(date): ✅ DEV MODE: Auto-exited (local changes pushed)"
                fi
            fi
        elif [ -f "$LOCAL_PATH" ]; then
            # Initialize sync state if it doesn't exist
            echo "local:$CURRENT_LOCAL_SIZE:$(stat -f%M "$LOCAL_PATH" 2>/dev/null || echo '0')" > "$LAST_SYNC_FILE"
        fi

        sleep 5  # Check every 5 seconds for bidirectional sync
    done
}

case "$1" in
    "pull")
        echo "Pulling debug.log from server..."
        curl -u "$REMOTE_USER:$FTP_PASS" "ftp://153.92.216.191$REMOTE_PATH" -o "$LOCAL_PATH"
        echo "Debug log updated from server"
        ;;
    "push")
        echo "Pushing debug.log to server..."
        curl -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://153.92.216.191$REMOTE_PATH"
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
        echo "Clearing local debug.log..."
        > "$LOCAL_PATH"
        echo "✓ Local debug.log cleared. Modify it manually for testing."
        echo "⚠️ Changes will be pushed to server when you resume sync."
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
