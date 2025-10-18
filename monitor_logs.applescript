tell application "Safari"
	activate
	delay 2

	-- Get initial logs
	tell front document
		set overrideJS to "if (!window.consoleLogs) { window.consoleLogs = []; window.originalConsoleLog = console.log; console.log = function() { var args = Array.prototype.slice.call(arguments); window.consoleLogs.push(args.join(' ')); window.originalConsoleLog.apply(console, arguments); }; }"
		do JavaScript overrideJS
		set currentLogs to do JavaScript "window.consoleLogs ? window.consoleLogs.join('\\n') : '';" as string
	end tell

	-- Write initial logs to file
	do shell script "echo " & quoted form of currentLogs & " > console.log"
	set currentLogs to do JavaScript "window.consoleLogs ? window.consoleLogs.join('\\n') : '';" as string

	repeat
		delay 5 -- Check every 5 seconds

		tell front document
			set newLogs to do JavaScript "window.consoleLogs ? window.consoleLogs.join('\\n') : '';" as string
		end tell

		if newLogs ≠ currentLogs then
			-- Update the file with new logs
			do shell script "echo " & quoted form of newLogs & " > console.log"
			set currentLogs to newLogs
		end if
	end repeat
end tell
