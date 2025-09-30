#!/bin/bash
# PuntWork Import Log Analysis Script
# Enhanced with AI-driven analysis patterns and metrics
# Run this script to analyze debug logs for performance insights

LOG_FILE="debug.log"

if [ ! -f "$LOG_FILE" ]; then
    echo "Error: Debug log file not found at $LOG_FILE"
    echo "Make sure the path is correct and the file exists."
    exit 1
fi

echo "=== PuntWork Import Log Analysis ==="
echo "Enhanced AI-Driven Analysis"
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

echo "7. AI-ENHANCED PERFORMANCE METRICS:"
echo "------------------------------------"
echo "AJAX request count:"
grep "AJAX Request:" "$LOG_FILE" | wc -l
echo
echo "AJAX success rate:"
TOTAL_AJAX=$(grep "AJAX Response:" "$LOG_FILE" | wc -l)
SUCCESS_AJAX=$(grep "AJAX Response:.*SUCCESS" "$LOG_FILE" | wc -l)
if [ "$TOTAL_AJAX" -gt 0 ]; then
    echo "scale=2; $SUCCESS_AJAX * 100 / $TOTAL_AJAX" | bc
else
    echo "No AJAX data"
fi
echo "%"
echo
echo "Feed processing efficiency (items/second):"
FEED_TIME=$(grep "Feed processing completed" "$LOG_FILE" | grep -o "processing_time[^}]*" | grep -o "[0-9.]*" | head -1)
FEED_ITEMS=$(grep "Feed processing completed" "$LOG_FILE" | grep -o "items_processed[^}]*" | grep -o "[0-9]*" | head -1)
if [ ! -z "$FEED_TIME" ] && [ ! -z "$FEED_ITEMS" ] && [ "$FEED_TIME" != "0" ]; then
    echo "scale=2; $FEED_ITEMS / $FEED_TIME" | bc
else
    echo "Insufficient data"
fi
echo

echo "8. AI-COMPREHENSION METRICS:"
echo "----------------------------"
echo "Code comprehension score references:"
grep "code_comprehension_score" "$LOG_FILE" | wc -l
echo
echo "AI suggestions accepted:"
grep "ai_suggestions_accepted" "$LOG_FILE" | wc -l
echo
echo "Context provision events:"
grep "ai_context_provided" "$LOG_FILE" | wc -l
echo

echo "9. RECOMMENDATIONS:"
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

# AI-driven recommendations
AJAX_ERRORS=$(grep "AJAX.*error" "$LOG_FILE" | wc -l)
if [ "$AJAX_ERRORS" -gt 0 ]; then
    echo "- $AJAX_ERRORS AJAX errors detected. Consider checking network connectivity and server response times."
fi

CONTEXT_EVENTS=$(grep "ai_context_provided" "$LOG_FILE" | wc -l)
if [ "$CONTEXT_EVENTS" -lt 5 ]; then
    echo "- Low AI context provision ($CONTEXT_EVENTS events). Consider increasing AI integration for better analysis."
fi

echo
echo "=== Analysis Complete ==="
echo "AI-Enhanced analysis provides deeper insights for optimization."
echo "For more detailed debugging, check the PHP debug scripts in the includes/ directory."