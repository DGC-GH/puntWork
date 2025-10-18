#!/bin/bash

# Protocol script for automated job feed import testing

echo "🔄 Starting protocol..."

# Step 1: Activate wordpress-debug.log monitoring
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

# Step 2: Clear local wordpress-debug.log file
echo "🧹 Clearing local wordpress-debug.log file..."
> wordpress-debug.log
echo "Debug log cleared"

# Step 3: Activate Safari browser or open it
echo "🌐 Activating Safari browser..."
osascript -e 'tell application "Safari" to activate' || open -a Safari
sleep 2

# Step 4: Activate tab with URL or open it
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

# Step 5: Activate constant console monitoring
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

# Step 6: Click import button
echo "🚀 Clicking import button..."
osascript browser-automation/click_import_button.applescript

# Step 7: Wait for import to start and finish
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

# Step 8: Analyze log files in detail
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

# Stop monitoring processes
echo "🛑 Stopping monitoring processes..."
kill $MONITOR_PID $CONSOLE_PID 2>/dev/null

echo "✅ Protocol completed!"
