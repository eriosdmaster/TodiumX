# TodiumX

Servidor Bedrock com suporte a plugins PHP e manifestos TOML.

Veja [PLUGINS.md](PLUGINS.md) e `examples/ExamplePlugin/` para APIs de plugins, itens, blocos lógicos, entidades, comandos e eventos. Packs de recursos ficam em `resource_packs/`.

**Novo:** suporte a behavior packs (add-ons de dados) — itens, blocos, entidades, receitas, funções e tabelas de packs em `behavior_packs/`, com os comandos `/behaviorpack` e `/function`. Veja [BEHAVIOR_PACKS.md](BEHAVIOR_PACKS.md).

**Novo:** suporte a **Script API** — packs com módulo `script` rodam JavaScript real (`import { world, system } from "@minecraft/server"`) no QuickJS embutido na biblioteca nativa (rquickjs 0.9): eventos before/after, timers `system.run*`, dynamic properties, `runCommand` e scriptevents, com os comandos `/scriptapi` e `/scriptevent`. Veja [SCRIPT_API.md](SCRIPT_API.md).

créditos para ByteForge88
