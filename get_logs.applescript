tell application "Safari"
	tell front document
		-- Override console.log to capture logs
		set overrideJS to "if (!window.consoleLogs) { window.consoleLogs = []; window.originalConsoleLog = console.log; console.log = function() { var args = Array.prototype.slice.call(arguments); window.consoleLogs.push(args.join(' ')); window.originalConsoleLog.apply(console, arguments); }; }"
		do JavaScript overrideJS
		
		-- Test log
		do JavaScript "console.log('Test log from automation - Heartbeat update simulation')"
		
		-- Get the captured logs
		set getLogsJS to "window.consoleLogs ? window.consoleLogs.join('\\n') : 'No logs captured yet';"
		set logs to do JavaScript getLogsJS
	end tell
	do shell script "echo '" & logs & "' > console.log"
	return logs
end tell
