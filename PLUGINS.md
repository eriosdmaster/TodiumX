# Plugins PHP (TodiumX)

## Instalação

Coloque cada plugin em `<data>/plugins/NomeDoPlugin/`. O arquivo obrigatório é `plugin.toml`; o código principal fica em `src/`. Um exemplo completo está em `examples/ExamplePlugin/`.

```text
<data>/plugins/MeuPlugin/plugin.toml
<data>/plugins/MeuPlugin/src/Fornecedor/MeuPlugin/Main.php
```

## Manifesto TOML

```toml
name = "MeuPlugin"
version = "1.0.0"
main = "Fornecedor\\MeuPlugin\\Main"
api = "1.0.0"
description = "Descrição opcional"
authors = ["Autor A", "Autor B"]
depend = []
softdepend = ["PluginOpcional"]
loadbefore = []

[[commands]]
name = "oi"
description = "Responde ao comando"
usage = "/oi"
permission = "meuplugin.oi"
aliases = ["ola"]

[[permissions]]
name = "meuplugin.oi"
description = "Permite usar /oi"
default = "true"
```

O leitor aceita strings básicas/literais, números, booleanos, arrays, tabelas e tabelas em array (`[[commands]]`). Manifestos `plugin.json` não são carregados. `main` implementa `raspberry\plugin\Plugin` (normalmente estendendo `PluginBase`). As classes seguem caminho PSR-4 a partir de `src/`.

## APIs de plugins

`PluginBase` fornece `getServer()`, `getLogger()`, `getDataFolder()`, `getDescription()`, `registerEvents()` e helpers para mundo, itens, blocos e entidades. O registro de eventos usa `Listener` e `#[EventHandler]`; os eventos básicos são entrada, saída e chat. Comandos TOML chamam `PluginCommandExecutor::onCommand()`.

Exemplo de registro em `onEnable()`:

```php
$this->registerCustomItem("meuplugin:token", [
    "minecraft:max_stack_size" => 16,
    "minecraft:icon" => ["texture" => "meuplugin.token"],
    "minecraft:display_name" => ["value" => "Token"],
]);
$item = $this->createCustomItem("meuplugin:token", 3);

$this->registerCustomBlock("meuplugin:marble", "minecraft:quartz_block");
$this->placeCustomBlock($world, 0, 70, 0, "meuplugin:marble");
$id = $this->getCustomBlockIdAt($world, 0, 70, 0);

$this->registerCustomEntity("meuplugin:scout", [
    "name" => "Scout", "max_health" => 20, "speed" => 0.1,
    "follow_range" => 16, "follow_players" => true, "wander" => true,
    "width" => 0.7, "height" => 1.8,
]);
$entity = $this->spawnCustomEntity("meuplugin:scout", 0.5, 70, 0.5, $world);
```

Itens registrados entram no dicionário de itens e na lista Criativa `Plugin Items`; o ícone usa a textura nomeada no resource pack ativo. Blocos customizados são IDs lógicos associados a um estado vanilla de fallback: a paleta atual do servidor ainda não registra um novo estado Bedrock, então aparência e colisão são as do fallback. `getCustomBlockIdAt()` recupera o ID lógico quando o estado vanilla corresponde a um fallback registrado.

Entidades são spawnadas e movimentadas pelo servidor. O resource pack do cliente precisa fornecer a definição visual associada ao mesmo identificador para mostrar um modelo customizado; sem ela, o cliente pode mostrar uma representação ausente. As opções aceitas são `name`, `max_health` (ou `health`), `speed`, `follow_range`, `follow_players`, `wander`, `width` e `height`.

## Resource packs

Resource packs continuam em `<data>/resource_packs/`; o servidor valida o manifesto, compacta os arquivos e negocia/download em chunks com clientes. Inclua nele os ícones e definições visuais para conteúdo customizado.

## Compilação nativa

```sh
php build/make-native.php
```

A biblioteca nativa selecionada depende do sistema operacional.
