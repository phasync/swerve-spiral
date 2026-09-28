# Benchmarks

The Spiral skeleton (`spiral/app` 3.9, Spiral 3.17.2) with the routes of the tests, served four
ways with 4 PHP workers each, opcache on, on one machine: `wrk -t4 -c64 -d10s`.

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
| Home page `/` (a Stempler view) | [241](results/fpm-home.txt) | [2,402](results/roadrunner-home.txt) | [2,312](results/swerve-home.txt) | [2,244](results/swerve-ext-home.txt) |
| JSON route `/swerve/json` | [245](results/fpm-json.txt) | [2,469](results/roadrunner-json.txt) | [2,382](results/swerve-json.txt) | [2,350](results/swerve-ext-json.txt) |
| Session counter `/swerve/counter` | [241](results/fpm-session.txt) | [2,090](results/roadrunner-session.txt) | [2,006](results/swerve-session.txt) | [1,723](results/swerve-ext-session.txt) |

- Every route runs the skeleton's `web` middleware (cookies, session, CSRF). The session page
  reads and writes a session in Spiral's default file handler. Each wrk thread cycles through 64
  sessions of its own ([sessions.lua](sessions.lua)): that handler has no lock, and one session
  used by several workers at once fails now and then, under RoadRunner as under swerve.
- Under PHP-FPM Spiral boots for every request, about 16 ms of a worker. Booted once, a request
  costs a worker about 1.7 ms, of which Spiral's `Http::handle()` takes about 85% (sampled with
  excimer): its container builds the pipeline's middleware and the controller anew for each request.
- With phasync-ext the file session handler's reads and writes wait as coroutines; with one
  request at a time per worker nothing else runs meanwhile, and the session page is 14% slower.

Machine and versions: [results/versions.txt](results/versions.txt) (2 × Xeon E5-2697 v3, 56
threads, PHP 8.5.11).
