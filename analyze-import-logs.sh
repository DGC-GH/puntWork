#!/bin/bash
# PuntWork Import Log Analysis Script
# Run this script to analyze debug logs for performance insights

LOG_FILE="/wp-content/debug.log"

if [ ! -f "$LOG_FILE" ]; then
    echo "Error: Debug log file not found at $LOG_FILE"
    echo "Make sure the path is correct and the file exists."
    exit 1
fi

echo "=== PuntWork Import Log Analysis ==="
echo "Analyzing: $LOG_FILE"
echo

echo "1. PERFORMANCE BOTTLENECKS (Top 10 slowest operations):"
echo "-----------------------------------------------------"
grep "PERF-MONITOR" "$LOG_FILE" | awk '{print $3, $5}' | sort -k2 -nr | head -10
echo

echo "2. ERROR PATTERNS:"
echo "------------------"
grep "ERROR\|FAILED" "$LOG_FILE" | cut -d' ' -f3- | sort | uniq -c | sort -nr | head -10
echo

echo "3. MEMORY USAGE TRENDS:"
echo "-----------------------"
echo "Average memory usage:"
grep "MEMORY" "$LOG_FILE" | grep -o "[0-9]*MB" | sed 's/MB//' | awk '{sum+=$1; count++} END {if(count>0) print "Average:", sum/count, "MB"; else print "No memory data found"}'
echo
echo "Peak memory usage:"
grep "MEMORY" "$LOG_FILE" | grep -o "[0-9]*MB" | sed 's/MB//' | sort -nr | head -1 | awk '{print $1, "MB"}'
echo

echo "4. IMPORT PROGRESS SUMMARY:"
echo "---------------------------"
echo "Total batches processed:"
grep "BATCH-FINALIZE" "$LOG_FILE" | wc -l
echo
echo "Import completion status:"
grep "IMPORT-COMPLETE\|IMPORT-FAILED" "$LOG_FILE" | tail -1
echo

echo "5. DATABASE PERFORMANCE:"
echo "------------------------"
echo "Slow queries (>0.1s):"
grep "SLOW-QUERY" "$LOG_FILE" | wc -l
echo
echo "Database operation times:"
grep "DB-PERF" "$LOG_FILE" | grep -o "[0-9.]* seconds" | sed 's/ seconds//' | awk '{sum+=$1; count++} END {if(count>0) print "Average DB time:", sum/count, "seconds"; else print "No DB performance data found"}'
echo

echo "6. RECOMMENDATIONS:"
echo "-------------------"
ERROR_COUNT=$(grep -c "ERROR\|FAILED" "$LOG_FILE")
if [ "$ERROR_COUNT" -gt 10 ]; then
    echo "- High error rate detected ($ERROR_COUNT errors). Review error patterns above."
fi

SLOW_BATCHES=$(grep "PERF-MONITOR" "$LOG_FILE" | awk '{if($5 > 30) print}' | wc -l)
if [ "$SLOW_BATCHES" -gt 0 ]; then
    echo "- $SLOW_BATCHES batches took longer than 30 seconds. Consider reducing batch size."
fi

MEMORY_WARNINGS=$(grep -c "MEMORY-WARNING" "$LOG_FILE")
if [ "$MEMORY_WARNINGS" -gt 0 ]; then
    echo "- $MEMORY_WARNINGS memory warnings detected. Consider increasing memory limit or optimizing memory usage."
fi

echo
echo "=== Analysis Complete ==="
echo "For detailed reports, use the PHP debug scripts:"
echo "- debug-import-performance.php"
echo "- debug-database-queries.php"
echo "- debug-memory-usage.php"