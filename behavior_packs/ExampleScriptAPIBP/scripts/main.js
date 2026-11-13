import { world, system } from "@minecraft/server";

/**
 * Exemplo de Script API do TodiumX.
 *
 * Este arquivo roda no host Node.js do TodiumX (resources/scriptapi/host.js) e
 * usa a mesma surface do @minecraft/server oficial implementada pelo servidor:
 * world.beforeEvents/afterEvents, system.run*, dynamic properties, runCommand e
 * scriptevents. Veja SCRIPT_API.md na raiz do projeto.
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
