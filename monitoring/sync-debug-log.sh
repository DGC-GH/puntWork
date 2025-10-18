#!/bin/bash

# WordPress Debug Log Sync Script
# Usage: ./sync-debug-log.sh [pull|push|watch|monitor]

SERVER="$PUNTWORK_SITE_URL"
REMOTE_USER="$PUNTWORK_FTP_USERNAME"
REMOTE_PATH="/wp-content/debug.log"
LOCAL_PATH="./debug.log"
FTP_PASS="$PUNTWORK_FTP_PASSWORD"

monitor_debug_log() {
    echo "Monitoring debug.log for changes from server..."
    echo "Press Ctrl+C to stop"

    while true; do
        # Get remote file timestamp/size
        REMOTE_INFO=$(curl -s -I --ftp-method EPSV "ftp://$REMOTE_USER:$FTP_PASS@$PUNTWORK_FTP_HOST$REMOTE_PATH" | grep -E "(Content-Length:|Last-Modified:)")
        REMOTE_SIZE=$(echo "$REMOTE_INFO" | grep "Content-Length:" | cut -d' ' -f2 | tr -d '\r')

        # Get local file size
        LOCAL_SIZE=$(stat -f%z "$LOCAL_PATH" 2>/dev/null || echo "0")

        if [ "$REMOTE_SIZE" != "$LOCAL_SIZE" ] && [ -n "$REMOTE_SIZE" ]; then
            echo "$(date): Change detected, pulling debug.log..."
            curl -s -u "$REMOTE_USER:$FTP_PASS" "ftp://$PUNTWORK_FTP_HOST$REMOTE_PATH" -o "$LOCAL_PATH"
            echo "✓ Updated from server"
        fi

        sleep 10  # Check every 10 seconds
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
        echo "  monitor - Monitor server for changes and pull automatically"
        exit 1
        ;;
esac
