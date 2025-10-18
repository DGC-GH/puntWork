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
    echo "Monitoring debug.log for bidirectional changes (auto-pause when editing locally)..."
    echo "Press Ctrl+C to stop"

    # Create tracking file for local changes
    LAST_SYNC_FILE="/tmp/debug_sync_state"
    DEV_MODE_FILE="/tmp/debug_dev_mode"

    while true; do
        # === DEVELOPMENT MODE DETECTION ===
        CURRENT_LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")

        # Get server size using lftp
        REMOTE_SIZE=$( /opt/homebrew/bin/lftp -c "set ssl:verify-certificate no; open -u '$REMOTE_USER','$FTP_PASS' ftp://$FTP_HOST; ls -la $REMOTE_PATH" | awk '{print $5}' | tail -n1 | tr -d '\r' || echo "0" )

        # Enhanced dev mode detection
        if [ -f "$LAST_SYNC_FILE" ]; then
            DEV_MODE_SERVER_SIZE=$(cat "$DEV_MODE_FILE" 2>/dev/null || echo "0")
            LAST_SERVER_SIZE=$(cat "$LAST_SYNC_FILE" | head -n1 | cut -d: -f2 2>/dev/null || echo "0")

            # Enter dev mode conditions:
            # 1. Empty file (user cleared it)
            # 2. Much smaller than server (user manually reduced for testing)
            # 3. Manual dev mode flag
            if [ "$CURRENT_LOCAL_SIZE" -eq 0 ] || \
               [ "$CURRENT_LOCAL_SIZE" -lt 50 -a "$LAST_SERVER_SIZE" -gt 500 ] || \
               [ -f "$DEV_MODE_FILE" ]; then

                # Record server size when dev mode activated
                if [ "$DEV_MODE_SERVER_SIZE" != "$REMOTE_SIZE" ]; then
                    echo "$REMOTE_SIZE" > "$DEV_MODE_FILE"
                    echo "$(date): 🛠️ DEV MODE: Activated - Protecting local changes from server overwrites"
                    echo "$(date):    Local: ${CURRENT_LOCAL_SIZE} bytes, Server: ${REMOTE_SIZE} bytes"
                fi
            fi
        fi

        # === SERVER → LOCAL DIRECTION ===
        if [ "$REMOTE_SIZE" != "$CURRENT_LOCAL_SIZE" ] && [ -n "$REMOTE_SIZE" ] && [ "$REMOTE_SIZE" != "0" ]; then
            DEV_MODE_SERVER_SIZE=$(cat "$DEV_MODE_FILE" 2>/dev/null || echo "0")

            # Enhanced logic: In dev mode, be MUCH more restrictive about server pulls
            ALLOW_PULL=true

            if [ -f "$DEV_MODE_FILE" ] && [ "$CURRENT_LOCAL_SIZE" -ne 0 ]; then
                # In dev mode, only pull if:
                # 1. Server grew SIGNIFICANTLY (3x) since dev mode started, indicating real errors
                # 2. OR server has major new content growth (+2000+ bytes)
                GROWTH_RATIO=$((REMOTE_SIZE / (DEV_MODE_SERVER_SIZE + 1)))
                if [ $GROWTH_RATIO -lt 3 ] && [ $((REMOTE_SIZE - DEV_MODE_SERVER_SIZE)) -lt 2000 ]; then
                    ALLOW_PULL=false
                    echo "$(date): 🔇 DEV MODE: Blocking server pull (in dev mode, minor server changes ignored)"
                else
                    echo "$(date): ⚠️ DEV MODE: Allowing server pull - significant error growth detected"
                    ALLOW_PULL=true
                fi
            elif [ "$CURRENT_LOCAL_SIZE" -eq 0 ]; then
                # When local is empty (user cleared), block pulls to allow testing
                ALLOW_PULL=false
                echo "$(date): 🔇 DEV MODE: Blocking server pull (local cleared, allowing manual testing)"
            fi

            if [ "$ALLOW_PULL" = true ]; then
                echo "$(date): 🔄 Server change detected, pulling to local..."
                curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE_PATH" -o "$LOCAL_PATH"
                echo "✓ Local updated from server (size: $REMOTE_SIZE bytes)"
                echo "server:$REMOTE_SIZE:$(stat -f%M $LOCAL_PATH 2>/dev/null || echo '0')" > "$LAST_SYNC_FILE"
            fi
        fi

        # === LOCAL → SERVER DIRECTION ===
        # Check if local file was modified since last sync
        if [ ! -f "$DEV_MODE_FILE" ] && [ -f "$LOCAL_PATH" ] && [ -f "$LAST_SYNC_FILE" ]; then
            LAST_LOCAL_MOD=$(cat "$LAST_SYNC_FILE" | cut -d: -f3 2>/dev/null)
            CURRENT_LOCAL_MOD=$(stat -f%M "$LOCAL_PATH" 2>/dev/null || echo '0')

            if [ "$CURRENT_LOCAL_MOD" != "$LAST_LOCAL_MOD" ] && [ "$CURRENT_LOCAL_SIZE" -gt 10 ]; then
                echo "$(date): 🔄 Local change detected, pushing to server..."
                curl -s -u "$REMOTE_USER:$FTP_PASS" -T "$LOCAL_PATH" "ftp://$FTP_HOST/$REMOTE_PATH"
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
