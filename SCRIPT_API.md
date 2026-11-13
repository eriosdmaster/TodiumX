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

### `world`

| Membro | Suporte |
| --- | --- |
| `sendMessage(mensagem)` | Broadcast para todos os jogadores (aceita `§` de cor) |
| `getAllPlayers()` / `getPlayers({name})` | Snapshot atualizado a cada tick do servidor |
| `getDimension(id)` | `"overworld"`, `"nether"`, `"the end"` ou o nome do mundo; ver `Dimension` abaixo |
| `getDynamicProperty` / `setDynamicProperty` / `clearDynamicProperty` | Valores `string`, `number`, `boolean` e `Vector3`; persistem em `script_data/dynamic_properties.json` |
| `afterEvents.playerJoin` | `{player, playerName}` |
| `afterEvents.playerLeave` | `{playerName, playerId}` |
| `afterEvents.playerSpawn` | Disparado junto do join com `initialSpawn: true` |
| `afterEvents.chatSend` / `playerSendMessage` | `{player, message}` |
| `beforeEvents.chatSend` / `playerSendMessage` | **Síncrono e cancelável**: `event.cancel = true` impede a mensagem |
| `beforeEvents.playerBreakBlock` / `playerPlaceBlock` / `playerInteractWithBlock` / `playerLeave` | Ignorados com um aviso no log (o servidor PHP ainda não emite eventos de bloco) |

### `system`

| Membro | Suporte |
| --- | --- |
| `currentTick` | Tick atual do servidor (sincronizado a cada tick) |
| `run(callback)` / `runTimeout(cb, ticks)` / `runInterval(cb, ticks)` / `clearRun(id)` | Timers executados no tick do servidor; erros vão para o log sem derrubar o host |
| `sendScriptEvent(id, mensagem)` | Distribui o evento para todos os scripts (loop local) |
| `afterEvents.scriptEventReceive` | Recebe eventos de `sendScriptEvent` e do comando `/scriptevent`, com filtro `{namespaces: [...]}` |
| `beforeEvents.startup` | Ignorado com aviso (não há fase de world init) |

### `Player` (retornado por eventos e `getAllPlayers()`)

`name`, `id`, `type`, `location` (atualizado a cada tick), `dimension`,
`sendMessage()`, `runCommand()` / `runCommandAsync()`, dynamic properties
(persistidas por nome do jogador).

### `Dimension`

`id`, `getPlayers()`, `runCommand()` / `runCommandAsync()` (permissão de console,
resposta com `{success, statusMessage}`), dynamic properties por dimensão.

### Não suportado (import falha com mensagem clara)

`@minecraft/server-ui`, `@minecraft/server-net`, `@minecraft/server-admin`,
`@minecraft/server-gametest`, `@minecraft/server-editor` e qualquer módulo
externo: scripts de pack não têm acesso a rede, arquivo
ou processo do host — só à API acima. O limite total por boot é de 8 MiB e 2000
arquivos `.js`.

## Comandos

| Comando | Efeito (permissão `op`) |
| --- | --- |
| `/scriptapi status` | Estado do host: rodando, engine (QuickJS embutido), nº de packs e erros de carga |
| `/scriptapi list` | Lista os packs de script carregados (ok / falhou) |
| `/scriptapi reload` | Reaplica os packs de script (para após `/behaviorpack enable/disable`) |
| `/scriptapi eval <código>` | Executa JS no contexto do host; só funciona se algum pack ativo declarar a capability `script_eval` (o pack de exemplo declara) |
| `/scriptevent <id> <mensagem>` | Mesmo comando do `bedrock_server` oficial: dispara `system.afterEvents.scriptEventReceive` (ex.: `/scriptevent demo:greet olá`) |

## Arquitetura

```
PHP (raspberry\pack\script\NativeScriptHost)        Rust (raspberry-native, script.rs)
--------------------------------------------        ----------------------------------
apiHostNew(dataDir)  -------- FFI ----------------> QuickJS (rquickjs 0.9) + loader
apiHostLoad(packs JSON) -----> módulos ES ----------> "<pack-id>|scripts/main.js"
apiHostTick(tick, players) --> __tosapiOnTick ------> timers system.run*
emit("playerJoin", ...) ------> __tosapiOnEmit -----> world.afterEvents.*
emit("beforeChatSend", ...) --> __tosapiOnEmit -----> world.beforeEvents.chatSend
      <------- resposta {"cancelled":true} ---------┘  (síncrono, direto no retorno)
apiHostCommandResult(reqId) -> __tosapiResolveCmd -> promessas de runCommand
apiHostEval(code) -----------> __tosapiEval -------> /scriptapi eval (limite 5 s)
apiHostDrain() <-- fila de ops <- __tosapiPush ----- world.sendMessage/console.log
```

- **PHP** (`src/raspberry/pack/script/`): `ScriptManager` (orquestra),
  `NativeScriptHost` (wrapper FFI sobre `rx_api_host_*` em `raspberry\native\Native`),
  `ScriptCommandSender` (CommandSender dos runCommands),
  `ServerScriptBridge` (implementa `ScriptApiBridge` contra o `Server` real),
  `ScriptApiBridge` (interface para os testes), `ScriptException`.
- **Rust** (`native/src/script.rs`): um runtime QuickJS compartilhado por todos os
  packs. Cada pack é avaliado como módulo ES chamado `<pack-id>|<entry>` e os
  imports relativos resolvem dentro do pack via o loader (`TosapiResolver` +
  `TosapiLoader`); `@minecraft/server` é um módulo fixo que reexporta a API do
  shim. Um runtime novo é criado a cada load (250 MiB de limite de memória,
  1 MiB de stack, eval com deadline de 5 s, rejection tracker para promessas
  rejeitadas).
- **JS** (`native/src/tosapi_shim.js`): o shim que implementa `world`, `system`,
  eventos, timers, dynamic properties e o protocolo de ops — a mesma surface do
  host oficial. `console.log` dos scripts vira log do servidor com o UUID do pack.

A comunicação é por fila de ops (JSON, uma op por linha) drenada pelo PHP a cada
tick: `log`, `message`, `playerMessage`, `command`, `loaded`, `response`, `done`.
O `tick()` do servidor nunca bloqueia esperando script — só
`beforeEvents.chatSend` e `/scriptapi eval` são síncronos (chamada FFI direta,
como o comportamento do jogo oficial). Rejeições de promessa não tratadas caem
no log em vez de sumirem.

## Testes

```
php tools/test_script_api.php
```

Verificações end-to-end com o host real (descoberta do pack em pasta e
`.mcpack`, carga, eventos, timers, cancelamento de chat, runCommand,
scriptevents, dynamic properties, shutdown). Sem a biblioteca nativa compilada
(`php build/make-native.php`) ou sem `ext-ffi`, a parte de runtime é marcada
como `SKIP` e a suíte sai com código 0. Existe também um harness em C
(`scripts/tosapi_harness.c` fora do projeto) que valida o `.so` diretamente.
