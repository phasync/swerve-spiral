#!/bin/bash
# PHP-FPM behind nginx, RoadRunner and swerve (without and with phasync-ext), each with 4 PHP
# workers and opcache on, serving benchmarks/app (made by setup.sh): wrk -t4 -c64 -d10s on the
# skeleton's home page, a JSON route and a page using the session (sessions.lua), in three
# rounds that take the servers in turn. Raw output in results/, medians in results/summary.txt.
#
#   benchmarks/setup.sh && benchmarks/run.sh
set -eu
cd "$(dirname "$0")"
here=$(pwd)
app=$here/app
ext=/home/frode/dev/phasync-ext/modules/phasync.so
fpm=/home/frode/dev/fpm-bench/serve.sh
lock=/home/frode/dev/fpm-bench/bench.lock
port=18910
mkdir -p results

# RoadRunner as the skeleton's .rr.yaml has it, with 4 workers and error-level logs
cat > "$app/.rr-bench.yaml" <<YAML
version: '3'
rpc:
    listen: 'tcp://127.0.0.1:$((port + 1))'
server:
    command: 'php -d opcache.enable_cli=1 app.php'
    relay: pipes
http:
    address: '127.0.0.1:$port'
    middleware: [gzip, static]
    static:
        dir: public
        forbid: [.php, .htaccess]
    pool:
        num_workers: 4
        supervisor:
            max_worker_memory: 100
logs:
    mode: production
    level: error
YAML

wait_up() {
    for _ in $(seq 1 100); do
        curl -s -o /dev/null "http://127.0.0.1:$port/swerve/json" && return
        sleep 0.1
    done
    echo "server did not start" >&2
    exit 1
}

bench() { # round, name, then the command that serves the app on $port
    local round=$1 name=$2
    shift 2
    (cd "$app" && exec "$@") >>"results/$name-server.log" 2>&1 &
    local pid=$!
    wait_up
    # 256 sessions, made with the headers wrk sends (Spiral signs a session with the client's headers)
    for _ in $(seq 1 256); do
        curl -s -o /dev/null -H 'User-Agent:' -c - "http://127.0.0.1:$port/swerve/counter" \
            | awk '/^#HttpOnly_/ || !/^#/ { if ($6 != "") printf "%s=%s; ", $6, $7 } END { print "" }'
    done >"results/cookies-$name.txt"
    flock "$lock" wrk -t4 -c64 -d3s "http://127.0.0.1:$port/" >/dev/null # warm-up
    for route in home:/ json:/swerve/json session:/swerve/counter; do
        local label=${route%%:*} path=${route#*:}
        local script=()
        [ "$label" = session ] && script=(-s sessions.lua)
        flock "$lock" wrk -t4 -c64 -d10s "${script[@]}" "http://127.0.0.1:$port$path" ${script:+"results/cookies-$name.txt"} >"results/$name-$label-$round.txt"
        echo "$round $name $label: $(grep Requests/sec "results/$name-$label-$round.txt")"
    done
    kill "$pid"
    wait "$pid" 2>/dev/null || true
}

{
    echo "Machine: $(lscpu | sed -n 's/^Model name: *//p'), $(nproc) threads"
    php -v | head -1
    echo "Spiral: $(cd "$app" && composer show spiral/framework | sed -n 's/^versions : //p')"
    echo "swerve: $(cd "$app" && composer show phasync/swerve | sed -n 's/^versions : //p')"
    echo "phasync-ext: $(php -d extension=$ext -r 'echo phpversion("phasync");')"
    "$app/rr" --version
    wrk --version 2>&1 | head -1
} >results/versions.txt

for round in 1 2 3; do
    bench $round fpm "$fpm" public $port 4
    bench $round roadrunner ./rr serve -c .rr-bench.yaml
    bench $round swerve php -d opcache.enable_cli=1 vendor/bin/swerve --workers=4 --no-access-log --http=127.0.0.1:$port swerve.php
    bench $round swerve-ext php -d opcache.enable_cli=1 -d extension=$ext vendor/bin/swerve --workers=4 --no-access-log --http=127.0.0.1:$port swerve.php
done

# The median of the three rounds
for name in fpm roadrunner swerve swerve-ext; do
    for label in home json session; do
        printf '%s %s %s\n' $name $label "$(cat results/$name-$label-?.txt | awk '/Requests\/sec/ {print $2}' | sort -n | sed -n 2p)"
    done
done >results/summary.txt
cat results/summary.txt
