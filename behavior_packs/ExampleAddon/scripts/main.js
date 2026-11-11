import { world, system } from "@minecraft/server";

// Demonstra o runtime Script API implementado pelo TodiumX.
world.sendMessage("[ExampleAddon] Script API iniciado.");
system.runInterval(() => {
  // Este ponto pode ser usado para tarefas periódicas do addon.
}, 20);

// Criação opcional de uma entidade definida no Behavior Pack:
// async function createExampleMob() {
//   const dimension = world.getDimension("minecraft:overworld");
//   const mob = await dimension.spawnEntity("example:mob", { x: 0.5, y: 70, z: 0.5 });
//   await mob?.teleport({ x: 4.5, y: 70, z: 0.5 });
// }
