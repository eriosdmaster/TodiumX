import { world, system } from "@minecraft/server";

/**
 * Exemplo de Script API do TodiumX.
 *
 * Este arquivo roda no host nativo do TodiumX (QuickJS embutido na
 * libraspberry_native.so, sem Node.js) e usa a surface @minecraft/server
 * declarada em ScriptApiRegistry (o manifest viaje para o host e o modulo
 * e montado por versao automaticamente): world.beforeEvents/afterEvents,
 * system.run*, dynamic properties, runCommand, scriptevents, entidades,
 * scoreboard, tags e eventos de bloco. Veja SCRIPT_API.md na raiz do projeto.
 */

// 1) world.afterEvents.playerJoin: boas-vindas quando um jogador entra
world.afterEvents.playerJoin.subscribe((event) => {
	world.sendMessage(`§a[ScriptAPI] Bem-vindo, ${event.playerName}!`);

	// dynamic properties persistem entre reinicios (script_data/dynamic_properties.json)
	const visits = (world.getDynamicProperty("joins") ?? 0) + 1;
	world.setDynamicProperty("joins", visits);
	world.sendMessage(`§7[ScriptAPI] joins acumulados desde o primeiro boot: ${visits}`);
});

// 2) world.beforeEvents.chatSend: eventos "before" sao sincronos e podem cancelar
world.beforeEvents.chatSend.subscribe((event) => {
	if (event.message.toLowerCase().includes("spam")) {
		event.cancel = true; // mensagem nunca chega aos outros jogadores
		event.player.sendMessage("§c[ScriptAPI] mensagem bloqueada pelo script (palavra proibida)");
	}
});

// 3) world.afterEvents.chatSend: reage depois que a mensagem foi enviada
world.afterEvents.chatSend.subscribe((event) => {
	if (event.message.startsWith("!ping")) {
		event.player.sendMessage("§b[ScriptAPI] pong! tick atual: " + system.currentTick);
	}
});

// 4) system.runInterval: roda a cada N ticks do servidor (20 ticks = 1s)
const statusRunId = system.runInterval(() => {
	const players = world.getAllPlayers();
	if (players.length > 0) {
		world.sendMessage(`§7[ScriptAPI] status: ${players.length} jogador(es) online no tick ${system.currentTick}`);
	}
}, 1200); // a cada 60 segundos

// 5) system.runTimeout: roda uma vez, 100 ticks depois do load
system.runTimeout(() => {
	world.sendMessage("§b[ScriptAPI] este pack esta no ar ha 5 segundos!");
}, 100);

// 6) system.afterEvents.scriptEventReceive + /scriptevent: comandos externos para scripts
system.afterEvents.scriptEventReceive.subscribe((event) => {
	world.sendMessage(`§d[ScriptAPI] scriptevent recebido: ${event.id} -> "${event.message}"`);

	if (event.id === "demo:clear") {
		// dimension.runCommand executa um comando com permissao de console
		world.getDimension("overworld").runCommand("say [ScriptAPI] demonstracao de runCommand via scriptevent").then((result) => {
			console.log("runCommand devolveu: " + JSON.stringify(result));
		});
	}
});

// 7) system.sendScriptEvent: scripts podem disparar scriptevents entre si
system.sendScriptEvent("demo:boot", "main.js carregado");

// 8) system.clearRun: cancele um timer quando nao precisar mais dele
world.afterEvents.playerLeave.subscribe((event) => {
	world.sendMessage(`§e[ScriptAPI] ${event.playerName} saiu (id ${event.playerId})`);
});

console.log("ExampleScriptAPIBP carregado; timer de status id=" + statusRunId);

// ---------------------------------------------------------------------------
// APIs novas (desde 1.16.0 do TodiumX): o modulo e montado a partir de
// ScriptApiRegistry — estas secoes so existem para packs que declaram >= 1.16.0
// ---------------------------------------------------------------------------

// 9) world.afterEvents.worldLoad: dispara uma vez, no primeiro tick do host
world.afterEvents.worldLoad.subscribe(() => {
	world.sendMessage("§a[ScriptAPI] mundo carregado - surface completa de API disponivel");
});

// 10) eventos de bloco (alimentados pelo engine via ScriptEventSink)
world.afterEvents.playerBreakBlock.subscribe((event) => {
	world.sendMessage(`§7[ScriptAPI] ${event.player.name} quebrou ${event.block.type} em ${event.block.location.x}, ${event.block.location.y}, ${event.block.location.z}`);
});

world.afterEvents.playerPlaceBlock.subscribe((event) => {
	world.sendMessage(`§7[ScriptAPI] ${event.player.name} colocou ${event.block.type}`);
});

// 11) world.getEntities / world.getEntity: promises resolvidas no proximo tick
world.getEntities().then((entities) => {
	world.sendMessage(`§7[ScriptAPI] ${entities.length} entidade(s) no servidor agora (world.getEntities)`);
	if (entities.length > 0) {
		world.sendMessage(`§7[ScriptAPI] primeira entidade: ${entities[0].name} (${entities[0].type})`);
	}
}).catch((error) => {
	console.error("world.getEntities falhou: " + error);
});

// 12) tags de jogador (valem por sessao) + getGameMode
world.afterEvents.playerJoin.subscribe((event) => {
	event.player.addTag("scriptapi:visited");
	world.sendMessage(`§7[ScriptAPI] tags de ${event.player.name}: [${event.player.getTags().join(", ")}] gamemode: ${event.player.getGameMode() ?? "desconhecido"}`);
});

// 13) scoreboard: world.scoreboard.* tambem e promise (ponte com o PHP)
world.scoreboard.addObjective("scriptapi_demo", "dummy", "§bScriptAPI§r").then((created) => {
	if (created) {
		world.sendMessage("§a[ScriptAPI] objetivo 'scriptapi_demo' criado no scoreboard");
	}
}).catch((error) => {
	console.warn("scoreboard.addObjective: " + error);
});
