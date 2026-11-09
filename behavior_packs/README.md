# Behavior packs

Put one folder per pack here. Each folder needs a `manifest.json` with a `data`, `script` or `client_data` module.

A pack found here is **not** enabled automatically. Enable it in game or from the console:

    /behaviorpack list
    /behaviorpack enable "Example Pack"
    /function hello

The choice is saved in `behavior_packs.json` next to `server.properties`. `/behaviorpack reload` scans the folder again.

What works today:

- `functions/**/*.mcfunction`: one command per line, `#` starts a comment, run with `/function <name>`.
  Functions can call other functions (up to 16 levels deep, 10000 commands per run).
- `functions/tick.json` (`{"values": ["name"]}`): functions that run every tick, without printing messages.
- Dependencies between packs (`dependencies` with a `uuid`) are checked; enabling a pack also enables the packs it needs.

Not done yet: sending packs to players, custom entities/items/blocks, and scripts (a pack with a `script` module loads but its scripts do not run).
