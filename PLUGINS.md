# PHP Plugins (TodiumX)

## Plugin location
Place folder plugins in your server data `plugins/` directory (same level as `behavior_packs/`, `worlds/`, etc.), for example:

```text
<data>/plugins/MyPlugin/plugin.json
<data>/plugins/MyPlugin/src/...
```

An example template is included at `examples/ExamplePlugin/`.

## Manifest (`plugin.json`)
```json
{
  "name": "MyPlugin",
  "version": "1.0.0",
  "main": "MyVendor\\MyPlugin\\Main",
  "api": "1.0.0",
  "description": "Optional description",
  "author": "Author",
  "authors": ["A", "B"],
  "depend": ["OtherPlugin"],
  "softdepend": ["OptionalPlugin"],
  "commands": {
    "mycmd": {
      "description": "My command",
      "usage": "/mycmd",
      "permission": "myplugin.mycmd",
      "aliases": ["mc"]
    }
  },
  "permissions": {
    "myplugin.mycmd": { "default": "true", "description": "Use /mycmd" }
  }
}
```

- `main` must be a class implementing `raspberry\plugin\Plugin` (usually extends `PluginBase`).
- Code is autoloaded from `src/` using PSR-4-style namespace-to-path mapping (e.g. `MyVendor\MyPlugin\Main` -> `src/MyVendor/MyPlugin/Main.php`).

## Plugin base
```php
use raspberry\plugin\PluginBase;

class Main extends PluginBase {
    public function onLoad() : void {}
    public function onEnable() : void {}
    public function onDisable() : void {}
}
```

Useful APIs: `$this->getServer()`, `$this->getLogger()`, `$this->getDataFolder()` (auto-created under `<data>/plugins/<Name>/`), `$this->getDescription()`.

## Events
Implement `raspberry\event\Listener` and register handlers:
```php
use raspberry\event\EventHandler;
use raspberry\event\Listener;
use raspberry\event\player\PlayerJoinEvent;

class Main extends PluginBase implements Listener {
    public function onEnable() : void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    #[EventHandler]
    public function onJoin(PlayerJoinEvent $event) : void {
        $event->getPlayer()->sendMessage("Welcome!");
    }
}
```

Built-in events currently wired: `PlayerJoinEvent`, `PlayerQuitEvent`, `PlayerChatEvent` (cancellable). The event system supports custom `raspberry\event\Event` subclasses.

## Commands
Declare commands in `plugin.json`. To handle them, implement `raspberry\plugin\PluginCommandExecutor`:
```php
use raspberry\plugin\PluginCommandExecutor;
use raspberry\command\Command;
use raspberry\command\CommandSender;

class Main extends PluginBase implements PluginCommandExecutor {
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        // handle and return true
        return true;
    }
}
```

## Permissions
Declare permissions in `plugin.json`; they are registered on load via the server permission manager.

## Native (Rust) builds for Linux and macOS
The native library is built with Rust/Cargo:
```bash
php build/make-native.php
# optional: php build/make-native.php --target x86_64-apple-darwin
```
Supported outputs: `libraspberry_native.so` (Linux), `libraspberry_native.dylib` (macOS), `raspberry_native.dll` (Windows). The PHP FFI loader selects the correct filename per OS.
