tell application "Safari"
	activate
	delay 2

	-- Get initial logs
	tell front document
		set overrideJS to "if (!window.consoleLogs) { window.consoleLogs = []; var methods = ['log', 'info', 'warn', 'error', 'debug', 'trace', 'assert', 'clear', 'count', 'countReset', 'group', 'groupEnd', 'groupCollapsed', 'table', 'time', 'timeEnd', 'timeLog', 'profile', 'profileEnd']; for (var i = 0; i < methods.length; i++) { var method = methods[i]; if (typeof console[method] === 'function') { var original = console[method]; console[method] = function() { var args = Array.prototype.slice.call(arguments); if (method !== 'clear') { window.consoleLogs.push('[' + method.toUpperCase() + '] ' + args.join(' ')); } original.apply(console, arguments); }; } }"
		do JavaScript overrideJS
		set currentLogs to do JavaScript "window.consoleLogs ? window.consoleLogs.join('\\n') : '';" as string
	end tell

	-- Write initial logs to file
	do shell script "echo " & quoted form of currentLogs & " > console.log"
	set currentLogs to do JavaScript "window.consoleLogs ? window.consoleLogs.join('\\n') : '';" as string

	repeat
		delay 5 -- Check every 5 seconds

		tell front document
			-- Re-establish override in case page was refreshed
			do JavaScript "
				if (!window.consoleLogs) {
					window.consoleLogs = [];
					var methods = ['log', 'info', 'warn', 'error', 'debug', 'trace', 'assert', 'clear', 'count', 'countReset', 'group', 'groupEnd', 'groupCollapsed', 'table', 'time', 'timeEnd', 'timeLog', 'profile', 'profileEnd'];
					for (var i = 0; i < methods.length; i++) {
						var method = methods[i];
						if (typeof console[method] === 'function' && !console[method].original) {
							var original = console[method];
							console[method] = function() {
								var args = Array.prototype.slice.call(arguments);
								if (method !== 'clear') {
									window.consoleLogs.push('[' + method.toUpperCase() + '] ' + args.join(' '));
								}
								original.apply(console, arguments);
							};
							console[method].original = original;
						}
					}
				}
			"
			set newLogs to do JavaScript "window.consoleLogs && window.consoleLogs.length > 0 ? window.consoleLogs.join('\\n') : '';" as string
		end tell

		if newLogs ≠ currentLogs and newLogs ≠ "" then
			-- Update the file with new logs
			do shell script "echo " & quoted form of newLogs & " > console.log"
			set currentLogs to newLogs
		end if
	end repeat
end tell
