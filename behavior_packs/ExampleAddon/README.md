# Example Addon (Behavior Pack)

Par BP + RP de exemplo. O BP depende do RP `ExampleAddonRP` (uuid no manifest) e inclui um módulo Script API com `@minecraft/server`.

Coloque as duas pastas e reinicie o servidor: os dois sao ativados sozinhos.

- `/function addon_hello` e `/function kit`
- `functions/tick.json` roda `heartbeat` todo tick
- `scripts/main.js` demonstra `world.sendMessage` e `system.runInterval`; o script requer um runtime JavaScript disponível no servidor
- item `example:gem` e definição da entidade `example:mob` pronta para spawn via Script API

O runtime do TodiumX implementa uma parte da superfície de `@minecraft/server` por meio de uma ponte própria; ele ainda não é compatível com toda a API oficial. Para criar a entidade no script, chame `world.getDimension("minecraft:overworld").spawnEntity("example:mob", {x: 0.5, y: 70, z: 0.5})`. Entidades podem passear, perseguir alvos definidos no Behavior Pack, receber dano, mudar de nome e ser removidas pelo Script API. Combate, física completa e navegação por caminhos ainda não fazem parte do motor.
