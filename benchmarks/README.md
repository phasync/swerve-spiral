# Benchmarks

The Spiral skeleton (`spiral/app` 3.9, Spiral 3.17.2) with the routes of the tests, served four
ways with 4 PHP workers each, opcache on, on one machine: `wrk -t4 -c64 -d10s`, three rounds
that take the servers in turn. The table has each median ([summary](results/summary.txt)); the
rounds lie within 4% of each other.

- **PHP-FPM** 4 children (`pm = static`, opcache without timestamp checks) behind nginx, through
  `spiral/sapi-bridge`: the skeleton is made for RoadRunner and has no front controller
  for FPM, so `setup.sh` adds the bridge and a `public/index.php` that boots as `app.php` does.
- **RoadRunner** 2025.1.15, the skeleton's own server and `app.php`, with 4 workers and
  error-level logs.
- **swerve** `--workers=4 --no-access-log`, without and with phasync-ext.

`setup.sh` makes the application in `app/` from the test application, set up for production as
the skeleton's `.env` asks (`APP_ENV=prod`, `DEBUG=false`, the tokenizer, view and Cycle schema
caches on). `run.sh` runs everything and writes `results/`; its paths to the PHP-FPM harness and
to phasync-ext are those of the machine it ran on.

| Requests per second | PHP-FPM | RoadRunner | swerve | swerve + phasync-ext |
|---|---:|---:|---:|---:|
| Home page `/` (a Stempler view) | [241](results/fpm-home-3.txt) | [2,402](results/roadrunner-home-1.txt) | [2,283](results/swerve-home-3.txt) | [2,292](results/swerve-ext-home-1.txt) |
| JSON route `/swerve/json` | [242](results/fpm-json-2.txt) | [2,525](results/roadrunner-json-3.txt) | [2,389](results/swerve-json-3.txt) | [2,394](results/swerve-ext-json-1.txt) |
| Session counter `/swerve/counter` | [236](results/fpm-session-3.txt) | [2,114](results/roadrunner-session-2.txt) | [2,050](results/swerve-session-1.txt) | [1,749](results/swerve-ext-session-2.txt) |

- Every route runs the skeleton's `web` middleware (cookies, session, CSRF) and the tests'
  authentication middleware. The session page
  reads and writes a session in Spiral's default file handler. Each wrk thread cycles through 64
  sessions of its own ([sessions.lua](sessions.lua)): that handler has no lock, and one session
  used by several workers at once fails now and then, under RoadRunner as under swerve.
- Under PHP-FPM Spiral boots for every request, about 16 ms of a worker. Booted once, a request
  costs a worker about 1.7 ms, of which Spiral's `Http::handle()` takes about 85% (sampled with
  excimer): its container builds the pipeline's middleware and the controller anew for each request.
- With phasync-ext the file session handler's reads and writes wait as coroutines; with one
  request at a time per worker nothing else runs meanwhile, and the session page is 15% slower.

Machine and versions: [results/versions.txt](results/versions.txt) (2 × Xeon E5-2697 v3, 56
threads, PHP 8.5.11).
