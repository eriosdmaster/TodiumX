# Behavior Packs no TodiumX

O TodiumX agora tem suporte a **behavior packs** (pacotes de comportamento), o
mesmo sistema que o `bedrock_server` oficial da Mojang usa para carregar add-ons
de dados: entidades, itens, blocos, receitas, funções, loot tables, trade tables,
spawn rules e features.

Este documento resume o que foi mapeado na engenharia reversa do binário oficial
(1.26.63.1) e como o sistema foi reimplementado em PHP dentro do TodiumX.

## Como usar

1. Coloque o behavior pack em `behavior_packs/`, como pasta ou `.mcpack`/`.zip`
   (o pack de exemplo `behavior_packs/ExampleAddonBP/` mostra todos os tipos de
   arquivo suportados).
2. Reinicie o servidor. Packs novos são ativados automaticamente; o estado fica
   em `behavior_packs.json` (mesmo formato do `resource_packs.json`).
3. Comandos (permissão `op`):

   | Comando | Efeito |
   | --- | --- |
   | `/behaviorpack list` | Lista os packs: ativo / esperando / desativado |
   | `/behaviorpack enable <uuid\|nome\|pasta>` | Ativa o pack e as dependências dele |
   | `/behaviorpack disable <uuid\|nome\|pasta>` | Desativa o pack e quem depende dele |
   | `/behaviorpack reload` | Relê a pasta e reaplica o stack |
   | `/function <caminho>` | Executa um `.mcfunction` de um pack ativo (ex.: `/function welcome`) |

## O que cada tipo de conteúdo faz no servidor

| Pasta do pack | O que o TodiumX faz |
| --- | --- |
| `items/*.json` | Registra o item no dicionário de itens (runtime id + NBT de componentes), adiciona ao criativo (grupo "Behavior Packs") e registra as tags de `minecraft:tags` |
| `blocks/*.json` | Registra o bloco (fallback `minecraft:stone` para o mundo) e envia a definição no StartGame via `BlockDefinitions` |
| `entities/*.json` | Registra a entidade (`is_summonable: true`): vida, movimento, follow range, collision box e comportamentos básicos viram uma `CustomEntity` spawnável por plugins |
| `recipes/*.json` | `recipe_shaped`, `recipe_shapeless`, `recipe_furnace` e `recipe_smithing_*` entram no CraftingManager e no CraftingDataPacket; ingredientes aceitam `item`, `tag`, `count` e `data` |
| `functions/*.mcfunction` | Registrados pelo caminho relativo e executados com `/function`; comentários `#` são ignorados |
| `loot_tables/`, `trade_tables/`, `spawn_rules/`, `features/`, `feature_rules/` | Validados, indexados e expostos por getters (`getLootTables()` etc.) para plugins usarem |
| `texts/` | Ignorado pelo servidor (usado pelo cliente) |

Regras do stack (iguais às do servidor oficial):

- A ordem do stack segue as dependências do `manifest.json` (`dependencies`).
- O conteúdo é aplicado em ordem de stack; tags e funções duplicadas entre packs
  resolvem para o primeiro pack ativo que as define.
- Um pack em `enabled` mas com dependência ausente fica "waiting" e não aplica
  conteúdo.
- `world_behavior_packs.json` de cada mundo em `worlds/` é **lido** (formato
  oficial `{"pack_id": uuid, "version": [x,y,z]}`): os packs listados são
  ativados, para manter compatibilidade com mundos criados no `bedrock_server`
  oficial. O arquivo nunca é escrito pelo TodiumX.

## Arquivos do sistema

- `src/raspberry/pack/BehaviorPack.php` — pack (pasta, `.zip` ou `.mcpack`) + índice de conteúdo
- `src/raspberry/pack/BehaviorPackManager.php` — descoberta, estado, stack, aplicação de conteúdo
- `src/raspberry/pack/PackContentParser.php` — JSON dos packs -> objetos do servidor
- `src/raspberry/pack/FunctionRunner.php` — execução de `.mcfunction`
- `src/raspberry/pack/PackManifest.php` — manifest (agora também módulos `data`/`script`, capabilities, versões)
- `src/raspberry/crafting/CraftingManager.php` — novos métodos `registerPack*` e `addItemTag`
- `src/raspberry/command/defaults/BehaviorPackCommand.php` e `FunctionCommand.php` — comandos (já existiam no projeto; agora registrados)
- `tools/test_behavior_packs.php` — suíte de testes (`php tools/test_behavior_packs.php`)

## O que a engenharia reversa encontrou no binário oficial

Análise de strings/RTTI do `bedrock_server` (ELF x64 stripped, 1.26.63.1):

- Pipeline: `RepositoryLoading::loadAndUpgradePacks(PackSource&, IPackManifestFactory&, IContentKeyProvider&, PackCommand::IPackCommandPipeline&)` carrega packs de fontes de diretório/arquivo.
- Manifest: `PackManifestDefinition::PackManifestDocument` com `Header`, `Module`, `PackDependency`, `NativeModuleDependency`, `RelaxedSemVersion`, `VersionSelectType` e settings (Toggle/Slider/Dropdown/MultiSelect), parseado com cereal/Puv.
- Cache: `valid_known_packs.json` / `invalid_known_packs.json`.
- Stack por mundo: `world_behavior_packs.json` / `world_resource_packs.json`.
- Capacidades: `PackCapability::ValidationResult` (Succeeded/Failed/NotFound) com providers de scripting, education, editor e raytracing.
- Conteúdo: `ActorDefinitionDescriptor`/`ActorDefinitions`, `ItemRegistryRef`/`ItemData` (sincronizados pelo ItemRegistryPacket), trade tables, loot tables, `minecraft:spawn_rules`, dimensions, scripts.
- Content log: `ContentLog` com endpoints de console/arquivo/script (`content-log-*-enabled` no server.properties) — o TodiumX emula isso com warnings por arquivo problemático.
