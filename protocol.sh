#!/bin/bash

# Protocol script for automated job feed import testing with step-by-step confirmation

echo "🔄 Starting protocol..."

# Function to wait for user confirmation
wait_for_confirmation() {
    STEP_NAME="$1"
    echo ""
    echo "✅ $STEP_NAME completed. Press Enter to continue to next step, or type 'skip' to skip next step, 'abort' to exit:"
    read -r USER_INPUT
    if [[ "$USER_INPUT" == "abort" ]]; then
        echo "❌ Protocol aborted by user"
        exit 1
    fi
    if [[ "$USER_INPUT" != "skip" ]]; then
        return 0
    else
        return 1
    fi
}

# Step 1: Cleanup any running monitoring processes
echo "🧹 Cleaning up any running monitoring processes..."
MPID_FILE="wordpress-debug-sync/wordpress-debug-monitor.pid"
if [ -f "$MPID_FILE" ]; then
    OLD_PID=$(cat "$MPID_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        kill "$OLD_PID" 2>/dev/null
        echo "Stopped previous debug monitoring (PID: $OLD_PID)"
    fi
    rm -f "$MPID_FILE"
fi

CONSOLE_PID_FILE="browser-automation/monitor-browser-console.pid"
if [ -f "$CONSOLE_PID_FILE" ]; then
    OLD_CONSOLE_PID=$(cat "$CONSOLE_PID_FILE")
    if kill -0 "$OLD_CONSOLE_PID" 2>/dev/null; then
        kill "$OLD_CONSOLE_PID" 2>/dev/null
        echo "Stopped previous console monitoring (PID: $OLD_CONSOLE_PID)"
    fi
    rm -f "$CONSOLE_PID_FILE"
fi
echo "Monitoring processes cleanup completed"

if wait_for_confirmation "Step 1: Process cleanup"; then
    # Step 2: Clear local log files (console, debug, import)
    echo "🧹 Clearing local log files (browser-console.log, wordpress-debug.log, import.log)..."
    > browser-console.log
    > wordpress-debug.log
    # Clear any import-specific log files that might exist
    if [ -f "import.log" ]; then
        > import.log
        echo "Cleared import.log"
    fi
    echo "Local log files cleared"
fi

if wait_for_confirmation "Step 2: Initial log file cleanup"; then
    # Step 3: Activate wordpress-debug.log monitoring
    echo "📊 Activating wordpress-debug.log monitoring..."
    MPID_FILE="wordpress-debug-sync/wordpress-debug-monitor.pid"
    if [ -f "$MPID_FILE" ]; then
        OLD_PID=$(cat "$MPID_FILE")
        if kill -0 "$OLD_PID" 2>/dev/null; then
            kill "$OLD_PID" 2>/dev/null
            echo "Stopped previous debug monitoring (PID: $OLD_PID)"
        fi
    fi
    ./wordpress-debug-sync/sync-debug-log.sh monitor &
    MONITOR_PID=$!
    echo $MONITOR_PID > "$MPID_FILE"
    echo "Debug monitoring started (PID: $MONITOR_PID)"
fi

if wait_for_confirmation "Step 4: Safari activation"; then
    # Step 5: Activate Safari browser or open it
    echo "🌐 Activating Safari browser..."
    osascript -e 'tell application "Safari" to activate' || open -a Safari
    sleep 2
fi

if wait_for_confirmation "Step 5: Dashboard navigation"; then
    # Step 6: Activate tab with URL or open it
    echo "📋 Activating/Opening the feeds dashboard tab..."
    osascript -e "
    tell application \"Safari\"
        set dashboardURL to \"https://belgiumjobs.work/wp-admin/admin.php?page=job-feed-dashboard\"
        set foundTab to false

        repeat with w in windows
            repeat with t in tabs of w
                if URL of t contains \"belgiumjobs.work\" and URL of t contains \"page=job-feed-dashboard\" then
                    tell w to set current tab to t
                    set foundTab to true
                    exit repeat
                end if
            end repeat
            if foundTab then exit repeat
        end repeat

        if not foundTab then
            open location dashboardURL
            delay 3
        end if
    end tell
    "
    echo "Feeds dashboard tab ready"
fi

if wait_for_confirmation "Step 6: Console monitoring"; then
    # Step 6: Activate constant console monitoring
    echo "📝 Starting constant console monitoring..."
    CONSOLE_PID_FILE="browser-automation/monitor-browser-console.pid"
    if [ -f "$CONSOLE_PID_FILE" ]; then
        OLD_CONSOLE_PID=$(cat "$CONSOLE_PID_FILE")
        if kill -0 "$OLD_CONSOLE_PID" 2>/dev/null; then
            kill "$OLD_CONSOLE_PID" 2>/dev/null
            echo "Stopped previous console monitoring (PID: $OLD_CONSOLE_PID)"
        fi
    fi
    osascript browser-automation/monitor-browser-console.applescript &
    CONSOLE_PID=$!
    echo $CONSOLE_PID > "$CONSOLE_PID_FILE"
    echo "Console monitoring started (PID: $CONSOLE_PID)"
fi

if wait_for_confirmation "Step 7: Import trigger"; then
    # Step 7: Click import button
    echo "🚀 Clicking import button..."
    osascript browser-automation/click_import_button.applescript
fi

if wait_for_confirmation "Step 8: Import progress monitoring"; then
    # Step 8: Wait for import to start and finish
    echo "⏳ Monitoring import progress..."
    IMPORT_STARTED=false
    IMPORT_COMPLETED=false

    while true; do
        # Check browser-console.log for import status
        if grep -q "Importing\.\.\." browser-console.log && ! $IMPORT_STARTED; then
            IMPORT_STARTED=true
            echo "▶️ Import started - found 'Importing...' in browser-console.log"
        fi

        if grep -q "completed successfully\|failed\|cancelled\|paused" browser-console.log && ! $IMPORT_COMPLETED; then
            echo "🏁 Import completed - found completion marker in browser-console.log"
            break
        fi

        # Also check wordpress-debug.log for server-side completion
        if grep -q "IMPORT.*SUCCESS\|IMPORT.*FAILED\|import.*complete" wordpress-debug.log && ! $IMPORT_COMPLETED; then
            echo "🌐 Import completed - found completion marker in wordpress-debug.log"
            break
        fi

        sleep 3
    done
fi

if wait_for_confirmation "Step 9: Stop monitoring"; then
    # Step 9: Stop monitoring processes
    echo "🛑 Stopping monitoring processes..."
    kill $MONITOR_PID $CONSOLE_PID 2>/dev/null

    # Ensure debug sync monitoring is killed
    MPID_FILE="wordpress-debug-sync/wordpress-debug-monitor.pid"
    if [ -f "$MPID_FILE" ]; then
        DEBUG_PID=$(cat "$MPID_FILE")
        if kill -0 "$DEBUG_PID" 2>/dev/null; then
            kill "$DEBUG_PID" 2>/dev/null
            echo "Stopped debug monitoring (PID: $DEBUG_PID)"
        fi
        rm -f "$MPID_FILE"
    fi

    # Ensure console monitoring is killed
    CONSOLE_PID_FILE="browser-automation/monitor-browser-console.pid"
    if [ -f "$CONSOLE_PID_FILE" ]; then
        BROWSER_PID=$(cat "$CONSOLE_PID_FILE")
        if kill -0 "$BROWSER_PID" 2>/dev/null; then
            kill "$BROWSER_PID" 2>/dev/null
            echo "Stopped console monitoring (PID: $BROWSER_PID)"
        fi
        rm -f "$CONSOLE_PID_FILE"
    fi

    echo "✅ Monitoring processes stopped!"
fi

if wait_for_confirmation "Step 10: Log analysis"; then
    # Step 10: Analyze log files in detail
    echo "📊 Analyzing log files..."

    echo "=== BROWSER-CONSOLE.LOG ANALYSIS ==="
    echo "Total lines: $(wc -l < browser-console.log)"
    echo "Heartbeat entries: $(grep -c "Heartbeat update" browser-console.log)"
    echo "Import duration: $(grep "Duration" browser-console.log | tail -1 || echo "Not found")"
    echo "Items processed: $(grep "Items Processed" browser-console.log | tail -1 || echo "Not found")"
    echo "Success rate: $(grep "Success Rate" browser-console.log | tail -1 || echo "Not found")"

    echo "=== WORDPRESS-DEBUG.LOG ANALYSIS ==="
    echo "Total lines: $(wc -l < wordpress-debug.log)"
    echo "PHP Fatal errors: $(grep -c "Fatal error" wordpress-debug.log)"
    echo "WPDB errors: $(grep -c "WordPress database error" wordpress-debug.log)"
    echo "Import-related entries: $(grep -i import wordpress-debug.log | wc -l)"
    echo "Last 5 lines:"
    tail -5 wordpress-debug.log

    echo "✅ Protocol completed!"
fi
