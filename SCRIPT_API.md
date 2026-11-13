# Script API no TodiumX

O TodiumX agora tem suporte à **Script API** do Minecraft Bedrock: behavior packs
que declaram um módulo `script` no `manifest.json` rodam **JavaScript real** num
QuickJS embutido na biblioteca nativa do TodiumX (Rust + rquickjs 0.9), com a
mesma surface do `@minecraft/server` oficial implementada pelo servidor. Nada de
Node.js: o motor vive dentro do `libraspberry_native` que o servidor já carrega.

```js
// behavior_packs/MeuPack/scripts/main.js
import { world, system } from "@minecraft/server";

world.afterEvents.playerJoin.subscribe((event) => {
	world.sendMessage(`Bem-vindo, ${event.playerName}!`);
});

system.runInterval(() => {
	world.sendMessage(`tick ${system.currentTick}`);
}, 600);
```

## Requisitos

| Requisito | Detalhe |
| --- | --- |
| Biblioteca nativa com Script API | `php build/make-native.php` (requer Rust/cargo). O host é o QuickJS 0.9 via **rquickjs 0.9** dentro do `bin/native/libraspberry_native.so` — o mesmo binário que o servidor já exige; a ABI nativa subiu para 6, então rebuild é obrigatório depois de atualizar. |
| PHP com `ext-ffi` | Já é requisito do `composer.json` do projeto. |
| `script-api-enabled` | Chave do `server.properties` (padrão `true`). Com `false`, a Script API fica desligada por completo. |

Não há `npm install` nem Node.js: o runtime é QuickJS (via rquickjs) compilado
junto da lib nativa, e os `import` ES modules dos packs são resolvidos por um
loader próprio em Rust a partir dos arquivos do pack (funciona com pack em
pasta e com `.zip`/`.mcpack`).

## Como usar

1. Coloque o pack com módulo `script` em `behavior_packs/` (pasta, `.zip` ou
   `.mcpack`) — o pack de exemplo `behavior_packs/ExampleScriptAPIBP/` demonstra
   toda a surface suportada.
2. O `manifest.json` precisa de um módulo `script` com `entry` apontando para o
   arquivo principal (relativo à raiz do pack), além da dependência do módulo:

```json
{
    "format_version": 2,
    "header": {
        "name": "Meu Pack",
        "uuid": "...",
        "version": [1, 0, 0],
        "capabilities": ["script_eval"]
    },
    "modules": [
        { "type": "data", "uuid": "...", "version": [1, 0, 0] },
        { "type": "script", "language": "javascript", "uuid": "...",
          "version": [1, 0, 0], "entry": "scripts/main.js" }
    ],
    "dependencies": [
        { "module_name": "@minecraft/server", "version": "1.16.0" }
    ]
}
```

3. Reinicie o servidor. No boot, o `ScriptManager` coleta todos os arquivos
   `scripts/*.js` de cada pack ativo (funciona para pack em pasta e em arquivo),
   envia ao host e reporta no log: `[ScriptAPI] loaded 1/1 script pack(s)`.
4. Erros de script (sintaxe, exceção no load, entry inexistente) aparecem no log
   com o UUID do pack e não derrubam o servidor.

## O que a surface suporta

A surface inteira é declarada em **um único lugar**: a tabela de dados em
`src/raspberry/pack/script/ScriptApiRegistry.php` (uma linha por API, com
`path`, versão `since` e `kind`). O shim monta o módulo `@minecraft/server`
**por versão do pack** a partir dessa tabela — nada é escrito à mão por API no
shim nem no Rust. Ver "Adicionando uma API nova" abaixo.

### `world`

| Membro | Suporte |
| --- | --- |
| `sendMessage(mensagem)` | Broadcast para todos os jogadores (aceita `§` de cor) |
| `getAllPlayers()` / `getPlayers({name})` | Snapshot atualizado a cada tick do servidor |
| `getDimension(id)` | `"overworld"`, `"nether"`, `"the end"` ou o nome do mundo; ver `Dimension` abaixo |
| `getDynamicProperty` / `setDynamicProperty` / `clearDynamicProperty` | Valores `string`, `number`, `boolean` e `Vector3`; persistem em `script_data/dynamic_properties.json` |
| `getEntity(id)` | **Promise** com o snapshot da entidade (jogador ou mob): `{id, name, type, dimension, x, y, z, yaw, pitch, health}`; id desconhecido resolve `null` |
| `getEntities()` | **Promise** com todos os jogadores e entidades (limite de 500) |
| `getBlock(x, y, z)` | **Promise** com `{type: "minecraft:stone", location}` do mundo padrão; `null` se o chunk não estiver carregado |
| `scoreboard.getObjective(name)` / `getObjectives()` | **Promise** com o objetivo (`{display_name, ...}`) ou lista |
| `scoreboard.addObjective(nome, criteria?, displayName?)` / `removeObjective(nome)` | **Promise** `boolean`; backing real é a `ScoreboardsAPI` do engine |
| `scoreboard.getScore(objetivo, participante)` / `setScore(obj, part, valor)` / `addScore(obj, part, qtd)` / `removeScore(obj, part)` | **Promise**; participante aceita `"Steve"`, um `Player` ou `{id, name}` — resolve para o nome |
| `afterEvents.playerJoin` | `{player, playerName}` |
| `afterEvents.playerLeave` | `{playerName, playerId}` |
| `afterEvents.playerSpawn` | Disparado junto do join com `initialSpawn: true` |
| `afterEvents.chatSend` / `playerSendMessage` | `{player, message}` |
| `afterEvents.playerBreakBlock` | `{player, block: {type, location}, dimension}` — emitido pelo engine (BlockBreaker) via `ScriptEventSink` |
| `afterEvents.playerPlaceBlock` | Idem, para blocos colocados (BlockPlacer) |
| `afterEvents.worldLoad` | Sem payload; dispara uma vez, no primeiro tick depois do load |
| `beforeEvents.chatSend` / `playerSendMessage` | **Síncrono e cancelável**: `event.cancel = true` impede a mensagem |
| `beforeEvents.playerBreakBlock` / `playerPlaceBlock` / `playerInteractWithBlock` / `playerLeave` | Ignorados com um aviso no log (os eventos de bloco existem só no fase `after`) |

Métodos marcados com **Promise** resolvem no tick seguinte (~50 ms), como
`runCommand()`. Erros do lado do servidor rejeitam a promessa com a mensagem do
log. Os membros de `world` estão disponíveis também via `/scriptapi eval`.

### `system`

| Membro | Suporte |
| --- | --- |
| `currentTick` | Tick atual do servidor (sincronizado a cada tick) |
| `run(callback)` / `runTimeout(cb, ticks)` / `runInterval(cb, ticks)` / `clearRun(id)` | Timers executados no tick do servidor; erros vão para o log sem derrubar o host |
| `sendScriptEvent(id, mensagem)` | Distribui o evento para todos os scripts (loop local) |
| `afterEvents.scriptEventReceive` | Recebe eventos de `sendScriptEvent` e do comando `/scriptevent`, com filtro `{namespaces: [...]}` |
| `beforeEvents.startup` | Ignorado com aviso (não há fase de world init) |

Constantes do módulo: `TicksPerSecond` (`20`) e `MinecraftDimensionTypes`.

### `Player` (retornado por eventos e `getAllPlayers()`)

`name`, `id`, `type`, `location`, `rotation` (`{x: pitch, y: yaw}`),
`dimension` (todos atualizados a cada tick), `gameMode` snapshot via
`getGameMode()`, `sendMessage()`, `runCommand()` / `runCommandAsync()`,
`teleport(x, y, z, dimensão?)` ou `teleport({x, y, z}, dimensão?)`
(**promise**, via bridge), tags de sessão `addTag()` / `getTags()` /
`removeTag()` / `clearTags()` e dynamic properties (persistidas por nome do
jogador).

### `Dimension`

`id`, `getPlayers()`, `runCommand()` / `runCommandAsync()` (permissão de console,
resposta com `{success, statusMessage}`), dynamic properties por dimensão.

### Não suportado (import falha com mensagem clara)

`@minecraft/server-ui`, `@minecraft/server-net`, `@minecraft/server-admin`,
`@minecraft/server-gametest`, `@minecraft/server-editor` e qualquer módulo
externo: scripts de pack não têm acesso a rede, arquivo
ou processo do host — só à API acima. O limite total por boot é de 8 MiB e 2000
arquivos `.js`.

## Versão da Script API

O servidor tem **uma** surface de Script API (a escrita contra `@minecraft/server`
**1.16.0**). O que dá para escolher é qual versão o servidor apresenta aos packs,
e o que acontece com um pack que pede uma versão mais nova.

Duas chaves no `server.properties`:

| Chave | Valores | Efeito |
| --- | --- | --- |
| `script-api-version` | `auto` (padrão) ou uma versão como `1.16.0`, `1.17.0-beta`, `2.0.0` | `auto`: cada pack roda com a versão que declara no manifest, sem limite. Versão fixa: é a **maior** versão de `@minecraft/server` que o servidor aceita. |
| `script-api-version-check` | `warn` (padrão), `strict`, `off` | O que fazer com um pack que declara versão maior que a fixada: `warn` avisa no log e carrega, `strict` não carrega o pack, `off` não diz nada. |

A versão que o pack declara é a de `dependencies` → `module_name: "@minecraft/server"`
no `manifest.json`. Packs mais antigos que a versão fixada sempre carregam.
`1.17.0-beta` vale mais que `1.16.0` e menos que `1.17.0`.

Pelo jogo, sem editar arquivo (precisa de permissão `op`; salva no
`server.properties` e recarrega os packs):

```
/scriptapi version              mostra a versão, o modo de checagem e o que cada pack declara
/scriptapi version 1.16.0       fixa a versão
/scriptapi version auto         volta para cada pack usar a sua
```

Limites: a versão declarada pelo pack agora **decide a surface do módulo** —
cada pack recebe um módulo `@minecraft/server` construído só com as linhas do
registro cujo `since` é menor ou igual à versão declarada. Um pack `1.0.0` não
vê `world.getEntity`; um pack `2.0.0` recebe tudo que o servidor implementa
(com o aviso no log de que a implementação vai até a 1.16.0). APIs que o
servidor não implementa continuam não existindo em nenhuma versão.

## Adicionando uma API nova (o registro automático)

Não é preciso mexer no shim (`tosapi_shim.js`) nem no Rust para expor uma API
nova — só em dados:

1. **Declare a linha** em `ScriptApiRegistry::SURFACES`
   (`src/raspberry/pack/script/ScriptApiRegistry.php`):

```php
["path" => "world.getSound", "since" => "1.16.0", "kind" => ScriptApiRegistry::KIND_BRIDGE],
```

   Kinds: `local` (implementado no shim, tabela `LOCAL_IMPL`), `bridge`
   (promise despachada ao PHP), `event` (alimentado por `emit`),
   `getter`/`const` e `stub` (nome oficial que aqui não existe: avisa e ignora).

2. **Se o servidor precisa agir**, trate o path em
   `ScriptManager::dispatchApiCall()` e implemente o método em
   `ScriptApiBridge` + `ServerScriptBridge` (e no `FakeBridge` dos testes):

```php
$path === "world.getSound" => $this->bridge->playSound(self::string($args, 0), ...),
```

   O resultado vira o valor resolvido da promise do script (JSON); uma exceção
   rejeita a promise com a mensagem do log.

3. **Eventos**: além da linha `KIND_EVENT`, o engine emite via
   `ScriptEventSink::playerXxx(...)` (attach no `Server.php`) e o
   `__tosapiOnEmit` do shim ganha o `case` com o payload.

Pronto: o rebuild da lib nativa só é necessário quando o **shim** mudar (novos
`LOCAL_IMPL`/`GETTER_IMPL` ou payload de evento); linhas `bridge` puras são só
PHP + dados. O `min_engine_version` do pack e a chave `script-api-version`
continuam controlando quem vê o quê, automaticamente.

## Comandos

| Comando | Efeito (permissão `op`) |
| --- | --- |
| `/scriptapi status` | Estado do host: rodando, engine (QuickJS embutido), nº de packs e erros de carga |
| `/scriptapi list` | Lista os packs de script carregados (ok / falhou) |
| `/scriptapi reload` | Reaplica os packs de script (para após `/behaviorpack enable/disable`) |
| `/scriptapi version [auto\|versão]` | Mostra ou muda a versão da Script API (ver seção acima) |
| `/scriptapi eval <código>` | Executa JS no contexto do host; só funciona se algum pack ativo declarar a capability `script_eval` (o pack de exemplo declara) |
| `/scriptevent <id> <mensagem>` | Mesmo comando do `bedrock_server` oficial: dispara `system.afterEvents.scriptEventReceive` (ex.: `/scriptevent demo:greet olá`) |

## Arquitetura

```
PHP (raspberry\pack\script\NativeScriptHost)        Rust (raspberry-native, script.rs)
--------------------------------------------        ----------------------------------
apiHostNew(dataDir)  -------- FFI ----------------> QuickJS (rquickjs 0.9) + loader
apiHostLoad({"packs":[...], "api": manifest}) -----> módulos ES "<pack-id>|scripts/main.js"
                                               \     "@minecraft/server@<versão do pack>"
                                                 \    (facade reexporta buildApi(versão))
apiHostTick(tick, players) --> __tosapiOnTick ------> timers system.run* + worldLoad
emit("playerJoin", ...) ------> __tosapiOnEmit -----> world.afterEvents.*
emit("beforeChatSend", ...) --> __tosapiOnEmit -----> world.beforeEvents.chatSend
      <------- resposta {"cancelled":true} ---------┘  (síncrono, direto no retorno)
apiHostCommandResult(reqId) -> __tosapiResolveCmd -> promessas de runCommand E apicall
apiHostEval(code) -----------> __tosapiEval -------> /scriptapi eval (limite 5 s)
apiHostDrain() <-- fila de ops <- __tosapiPush ----- log/message/playerMessage/
                                                      command/apicall/loaded/done
```

- **PHP** (`src/raspberry/pack/script/`): `ScriptManager` (orquestra e despacha
  os `apicall`), `NativeScriptHost` (wrapper FFI sobre `rx_api_host_*` em
  `raspberry\native\Native`), `ScriptCommandSender` (CommandSender dos
  runCommands), `ServerScriptBridge` (implementa `ScriptApiBridge` contra o
  `Server` real: entidades, teleport, scoreboard via `ScoreboardsAPI`),
  `ScriptApiBridge` (interface para os testes), `ScriptApiRegistry` (a tabela
  de dados da surface), `ScriptEventSink` (engine -> eventos de bloco),
  `ScriptException`.
- **Rust** (`native/src/script.rs`): um runtime QuickJS compartilhado por todos os
  packs. Cada pack é avaliado como módulo ES chamado `<pack-id>|<entry>` e os
  imports relativos resolvem dentro do pack via o loader (`TosapiResolver` +
  `TosapiLoader`); `@minecraft/server` resolve para `@minecraft/server@<versão
  declarada pelo pack>` e o loader monta uma facade por versão que reexporta
  `globalThis.__tosapi.buildApi(versão)`. O manifest da `ScriptApiRegistry`
  viaja no payload do `apiHostLoad`. Um runtime novo é criado a cada load
  (250 MiB de limite de memória, 1 MiB de stack, eval com deadline de 5 s,
  rejection tracker para promessas rejeitadas).
- **JS** (`native/src/tosapi_shim.js`): o shim guarda o estado compartilhado
  (timers, eventos, dynamic properties, snapshots) e **monta a API por versão**
  a partir do manifest (`__tosapiBuildApi`): kind `local` busca em `LOCAL_IMPL`,
  `bridge` gera a promise de `{"op":"apicall"}`, `event` usa `subscribeList`,
  `getter`/`const`/`stub` têm tabelas próprias. `console.log` dos scripts vira
  log do servidor com o UUID do pack.

A comunicação é por fila de ops (JSON, uma op por linha) drenada pelo PHP a cada
tick: `log`, `message`, `playerMessage`, `command`, `apicall`, `loaded`,
`response`, `done`. O `tick()` do servidor nunca bloqueia esperando script — só
`beforeEvents.chatSend` e `/scriptapi eval` são síncronos (chamada FFI direta,
como o comportamento do jogo oficial). Rejeições de promessa não tratadas caem
no log em vez de sumirem.

## Testes

```
php tools/test_script_api.php
```

Verificações end-to-end com o host real (descoberta do pack em pasta e
`.mcpack`, carga, eventos, timers, cancelamento de chat, runCommand,
scriptevents, dynamic properties, shutdown) e do registro: filtro de surface
por versão (pack `1.0.0` sem as APIs novas, pack `1.16.0`/`2.0.0-beta` com
tudo), round trip `apicall` (getEntities/getEntity/teleport/scoreboard/
getBlock), tags e getGameMode síncronos, eventos de bloco e `worldLoad`.
Sem a biblioteca nativa compilada (`php build/make-native.php`) ou sem
`ext-ffi`, a parte de runtime é marcada como `SKIP` e a suíte sai com código 0.
