#!/bin/bash

# Auto-start Debug Log Monitor
# This script ensures the monitoring process runs continuously

MONITOR_LOG="$HOME/debug-monitor.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MONITOR_PID_FILE="$SCRIPT_DIR/wordpress-debug-monitor.pid"

echo "$(date): Starting debug log monitoring..." >> "$MONITOR_LOG"

# Function to start monitoring
start_monitoring() {
    echo "$(date): Starting monitoring process..." >> "$MONITOR_LOG"
    # Stay in the original working directory (project root) to match paths
    nohup "$SCRIPT_DIR"/sync-debug-log.sh monitor >> "$MONITOR_LOG" 2>&1 &
    echo $! > "$MONITOR_PID_FILE"
    echo "$(date): Monitor started with PID $!" >> "$MONITOR_LOG"
}

# Function to check if monitoring is running
is_monitoring_running() {
    if [ -f "$MONITOR_PID_FILE" ]; then
        PID=$(cat "$MONITOR_PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            return 0  # Running
        else
            echo "$(date): Monitor process $PID is dead, cleaning up..." >> "$MONITOR_LOG"
            rm -f "$MONITOR_PID_FILE"
            return 1  # Not running
        fi
    else
        return 1  # No PID file
    fi
}

# Function to stop monitoring
stop_monitoring() {
    if [ -f "$MONITOR_PID_FILE" ]; then
        PID=$(cat "$MONITOR_PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            echo "$(date): Stopping monitor process $PID..." >> "$MONITOR_LOG"
            kill "$PID"
            rm -f "$MONITOR_PID_FILE"
        else
            rm -f "$MONITOR_PID_FILE"
        fi
    fi
}

case "$1" in
    "start")
        if is_monitoring_running; then
            PID=$(cat "$MONITOR_PID_FILE")
            echo "Monitoring already running with PID $PID"
            exit 0
        else
            start_monitoring
            echo "Monitoring started. PID: $(cat "$MONITOR_PID_FILE")"
        fi
        ;;
    "stop")
        stop_monitoring
        echo "Monitoring stopped"
        ;;
    "restart")
        stop_monitoring
        sleep 2
        start_monitoring
        echo "Monitoring restarted. PID: $(cat "$MONITOR_PID_FILE")"
        ;;
    "status")
        if is_monitoring_running; then
            PID=$(cat "$MONITOR_PID_FILE")
            echo "Monitoring is running with PID $PID"
        else
            echo "Monitoring is not running"
        fi
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        echo "  start   - Start monitoring if not already running"
        echo "  stop    - Stop monitoring"
        echo "  restart - Restart monitoring"
        echo "  status  - Check if monitoring is running"
        exit 1
        ;;
esac
