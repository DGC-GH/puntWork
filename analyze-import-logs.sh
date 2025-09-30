#!/bin/bash
# PuntWork Import Log Analysis Script
# Run this script to analyze debug logs for performance insights

LOG_FILE="wp-content/debug.log"

if [ ! -f "$LOG_FILE" ]; then
    echo "Error: Debug log file not found at $LOG_FILE"
    echo "Make sure the path is correct and the file exists."
    exit 1
fi

echo "=== PuntWork Import Log Analysis ==="
echo "Analyzing: $LOG_FILE"
echo

echo "1. IMPORT ACTIVITY SUMMARY:"
echo "---------------------------"
echo "Total plugin initializations:"
grep "PLUGIN-LOAD" "$LOG_FILE" | wc -l
echo
echo "Import attempts:"
grep "log_manual_import_run" "$LOG_FILE" | wc -l
echo
echo "Import failures:"
grep '"success":"false"' "$LOG_FILE" | wc -l
echo
echo "Import successes:"
grep '"success":"true"' "$LOG_FILE" | wc -l
echo

echo "2. MEMORY USAGE ANALYSIS:"
echo "-------------------------"
echo "Memory usage reports:"
grep "Memory usage" "$LOG_FILE" | wc -l
echo
echo "Average memory usage (MB):"
grep "Memory usage:" "$LOG_FILE" | grep -o "[0-9]* bytes" | sed 's/ bytes//' | awk '{sum+=$1; count++} END {if(count>0) print sum/count/1024/1024; else print "No data"}'
echo
echo "Peak memory usage (MB):"
grep "Peak memory usage:" "$LOG_FILE" | grep -o "[0-9]* bytes" | sed 's/ bytes//' | awk 'BEGIN{max=0} {if($1>max) max=$1} END {print max/1024/1024}'
echo

echo "3. FEED PROCESSING ACTIVITY:"
echo "----------------------------"
echo "Feed starts:"
grep "FEEDS-START" "$LOG_FILE" | wc -l
echo
echo "Feed processing:"
grep "FEEDS-PROCESS" "$LOG_FILE" | wc -l
echo
echo "Jobs added from feeds:"
grep "FEEDS-ADDED" "$LOG_FILE" | wc -l
echo
echo "Feed completions:"
grep "FEEDS-END" "$LOG_FILE" | wc -l
echo

echo "4. PROCESSING ACTIVITY:"
echo "-----------------------"
echo "Process starts:"
grep "PROCESS-START" "$LOG_FILE" | wc -l
echo

echo "5. ERROR ANALYSIS:"
echo "------------------"
echo "Failed imports with details:"
grep '"success":"false"' "$LOG_FILE" | sed 's/.*"error_message":"\([^"]*\)".*/\1/' | sort | uniq -c | sort -nr
echo

echo "6. TIMING ANALYSIS:"
echo "-------------------"
echo "Log time span:"
FIRST_TIME=$(head -1 "$LOG_FILE" | grep -o "\[.*\]" | head -1)
LAST_TIME=$(tail -1 "$LOG_FILE" | grep -o "\[.*\]" | tail -1)
echo "From: $FIRST_TIME"
echo "To:   $LAST_TIME"
echo

echo "7. RECOMMENDATIONS:"
echo "-------------------"
FAILED_IMPORTS=$(grep '"success":"false"' "$LOG_FILE" | wc -l)
if [ "$FAILED_IMPORTS" -gt 0 ]; then
    echo "- $FAILED_IMPORTS import failures detected. Check error messages above."
fi

FEED_STARTS=$(grep "FEEDS-START" "$LOG_FILE" | wc -l)
FEED_ENDS=$(grep "FEEDS-END" "$LOG_FILE" | wc -l)
if [ "$FEED_STARTS" -gt "$FEED_ENDS" ]; then
    echo "- Incomplete feed processing detected ($FEED_STARTS starts, $FEED_ENDS ends)."
fi

MEMORY_CHECKS=$(grep "Memory usage" "$LOG_FILE" | wc -l)
if [ "$MEMORY_CHECKS" -gt 0 ]; then
    echo "- Memory monitoring is active ($MEMORY_CHECKS checks)."
fi

echo
echo "=== Analysis Complete ==="
echo "For more detailed debugging, check the PHP debug scripts in the includes/ directory."