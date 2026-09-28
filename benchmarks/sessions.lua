-- Each wrk thread cycles through 64 sessions of its own (args[1], one Cookie header per line),
-- more than its 16 connections, so no two requests in flight share a session: Spiral's file
-- sessions have no lock, and one session hit at once from several workers fails now and then.
local threads = 0

function setup(thread)
    thread:set("id", threads)
    threads = threads + 1
end

function init(args)
    cookies = {}
    for line in io.lines(args[1]) do
        cookies[#cookies + 1] = line
    end
    n = 0
end

function request()
    n = n + 1
    return wrk.format(nil, nil, { Cookie = cookies[id * 64 + n % 64 + 1] })
end
