#!/bin/bash

# Protocol script for automated job feed import testing

echo "🔄 Starting protocol..."

# Step 1: Activate debug.log monitoring
echo "📊 Activating debug.log monitoring..."
./monitoring/sync-debug-log.sh monitor &
MONITOR_PID=$!
echo "Debug monitoring started (PID: $MONITOR_PID)"

# Step 2: Clear local debug.log file
echo "🧹 Clearing local debug.log file..."
> debug.log
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
osascript monitor_logs.applescript &
CONSOLE_PID=$!
echo "Console monitoring started (PID: $CONSOLE_PID)"

# Step 6: Click import button
echo "🚀 Clicking import button..."
osascript click_import_button.applescript

# Step 7: Wait for import to start and finish
echo "⏳ Monitoring import progress..."
IMPORT_STARTED=false
IMPORT_COMPLETED=false

while true; do
    # Check console.log for import status
    if grep -q "Importing\.\.\." console.log && ! $IMPORT_STARTED; then
        IMPORT_STARTED=true
        echo "▶️ Import started - found 'Importing...' in console.log"
    fi

    if grep -q "completed successfully\|failed\|cancelled\|paused" console.log && ! $IMPORT_COMPLETED; then
        echo "🏁 Import completed - found completion marker in console.log"
        break
    fi

    # Also check debug.log for server-side completion
    if grep -q "IMPORT.*SUCCESS\|IMPORT.*FAILED\|import.*complete" debug.log && ! $IMPORT_COMPLETED; then
        echo "🌐 Import completed - found completion marker in debug.log"
        break
    fi

    sleep 3
done

# Step 8: Analyze log files in detail
echo "📊 Analyzing log files..."

echo "=== CONSOLE.LOG ANALYSIS ==="
echo "Total lines: $(wc -l < console.log)"
echo "Heartbeat entries: $(grep -c "Heartbeat update" console.log)"
echo "Import duration: $(grep "Duration" console.log | tail -1 || echo "Not found")"
echo "Items processed: $(grep "Items Processed" console.log | tail -1 || echo "Not found")"
echo "Success rate: $(grep "Success Rate" console.log | tail -1 || echo "Not found")"

echo "=== DEBUG.LOG ANALYSIS ==="
echo "Total lines: $(wc -l < debug.log)"
echo "PHP Fatal errors: $(grep -c "Fatal error" debug.log)"
echo "WPDB errors: $(grep -c "WordPress database error" debug.log)"
echo "Import-related entries: $(grep -i import debug.log | wc -l)"
echo "Last 5 lines:"
tail -5 debug.log

# Stop monitoring processes
echo "🛑 Stopping monitoring processes..."
kill $MONITOR_PID $CONSOLE_PID 2>/dev/null

echo "✅ Protocol completed!"
