tell application "Safari"
	activate
	delay 1
	tell front document
		do JavaScript "confirm = function(){ return true; };"
		do JavaScript "document.querySelector('#run-now').click();"
	end tell
	delay 3
	set consoleLogs to do JavaScript "document.querySelector('#log-textarea').textContent;" in front document
	return consoleLogs
end tell
