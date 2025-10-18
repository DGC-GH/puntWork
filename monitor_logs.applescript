-- Kill any existing monitoring processes
do shell script "pkill -f monitor_logs.applescript 2>/dev/null || true"

-- Log startup
do shell script "echo '[MONITORING STARTUP]' `date '+%Y-%m-%dT%H:%M:%S%z'` 'Console monitoring starting (single instance ensured)' >> console.log"

tell application "Safari"
	activate
	delay 2

	-- Find the tab with the job feed dashboard
	set foundTab to missing value
	repeat with W in windows
		repeat with T in tabs of W
			if URL of T contains "job-feed-dashboard" then
				set foundTab to T
				exit repeat
			end if
		end repeat
		if foundTab is not missing value then exit repeat
	end repeat

	if foundTab is not missing value then
		-- Tab found, will use it directly (assume it's accessible)
	else
		-- Open the page in new tab
		open location "https://belgiumjobs.work/wp-admin/admin.php?page=job-feed-dashboard"
		delay 5
		set foundTab to tab 1 of window 1
	end if

	-- Work with the found tab
	tell foundTab
		-- Refresh the page to trigger logs (but optional, since user is on it)
		-- do JavaScript "location.reload();" in foundTab
		-- delay 3
	end tell

	-- Get initial logs
	tell foundTab
		set overrideJS to "
(function() {
  window.console.originalMethods = window.console.originalMethods || {};
  ['log', 'info', 'warn', 'error', 'debug', 'trace', 'assert'].forEach(function(method) {
    if (typeof console[method] === 'function') {
      // Store original only once
      if (!window.console.originalMethods[method]) {
        window.console.originalMethods[method] = console[method];
      }
      console[method] = function() {
        var args = Array.prototype.slice.call(arguments);
        var message = new Date().toISOString() + ' [' + method.toUpperCase() + '] ' + args.join(' ');
        var logCount = parseInt(localStorage.getItem('log_count') || '0', 10) + 1;
        localStorage.setItem('log_' + logCount, message);
        localStorage.setItem('log_count', logCount.toString());
        // Call the original (stored when first overridden)
        window.console.originalMethods[method].apply(console, args);
      };
      console[method].overridden = true;
    }
  });
})();
"
		do JavaScript overrideJS
	end tell

	set previousCount to 0

	repeat
		delay 2 -- Check every 2 seconds

		tell foundTab
			-- Re-establish override in case page was refreshed
			do JavaScript "
				if (typeof window.monitoringActive === 'undefined') {
					window.monitoringActive = true;
					(function() {
						var logCount = parseInt(localStorage.getItem('log_count') || '0', 10);
						window.console.originalMethods = window.console.originalMethods || {};
						['log', 'info', 'warn', 'error', 'debug', 'trace', 'assert'].forEach(function(method) {
							if (typeof console[method] === 'function' && !console[method].overridden) {
								window.console.originalMethods[method] = console[method];
								console[method] = function() {
									var args = Array.prototype.slice.call(arguments);
									var message = new Date().toISOString() + ' [' + method.toUpperCase() + '] ' + args.join(' ');
									logCount++;
									localStorage.setItem('log_' + logCount, message);
									localStorage.setItem('log_count', logCount.toString());
									window.console.originalMethods[method].apply(console, args);
								};
								console[method].overridden = true;
							}
						});
						console.log('[MONITORING] Console capture established -', new Date().toISOString());
					})();
				}
			"

			-- Retrieve new logs from localStorage
			set currentCount to "0"
			try
				set currentCount to do JavaScript "localStorage.getItem('log_count') || '0'"
			end try
			set countNum to currentCount as number
			if countNum > (my previousCount as number) then
				set newLogsText to ""
				repeat with i from ((my previousCount as number) + 1) to countNum
					set logKey to ("log_" & i) as string
					set logValue to ""
					try
						set logValue to do JavaScript ("localStorage.getItem('" & logKey & "')")
					end try
					if logValue ≠ "" then
						if newLogsText ≠ "" then set newLogsText to newLogsText & "\n"
						set newLogsText to newLogsText & logValue
					end if
				end repeat
				if newLogsText ≠ "" then
					do shell script "echo " & quoted form of newLogsText & " >> console.log"
					set my previousCount to countNum
				end if
			end if
		end tell


	end repeat
end tell
