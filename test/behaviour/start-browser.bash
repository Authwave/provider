#!/bin/bash
cd "$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
rm -f browser.pid
$1 --user-data-dir=$2 --disable-gpu --headless --no-sandbox --window-size='1920,1080' --disable-dev-shm-usage --no-startup-window --no-first-run --no-pings --remote-debugging-address=0.0.0.0 --remote-debugging-port=9222 &> /dev/null &
echo $! > browser.pid
# TODO: Got to wait for port to bind, what is the proper way to wait?
sleep 1