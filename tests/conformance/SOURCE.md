# Conformance suite

`server_conformance.py` checks any PAP/1 server against [`docs/PROTOCOL.md`](../../docs/PROTOCOL.md) as a black box.
It is standard-library Python 3.9+ with no dependencies; it is kept identical to the copy the PrintDock agent's
own test setup uses, and changes together with the protocol.

`run.sh` starts `examples/standalone` on a throw-away SQLite file and runs the suite twice:

1. `--admin-url` mode: the example implements the test admin API the suite drives, so every check runs.
2. black-box mode (`--url --key --enqueue-cmd`): the example is treated like any foreign server; checks that
   need server-side knobs (expiry, content_url, revocation, discovery) are reported as SKIP by the suite itself.
