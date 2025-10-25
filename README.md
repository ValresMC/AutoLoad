# AutoLoad

Automatic class loading system for PocketMine-MP plugins.

## Features

- 🚀 Automatic command registration
- 📡 Automatic event listener registration
- 🔧 Custom class autoloading with callbacks
- 📊 Priority-based loading order
- 📝 Load summary reporting

## Installation

Copy the `AutoLoad` directory into your plugin.

## Quick Start

```php
use Valres\AutoLoad\AutoLoad;

class MyPlugin extends PluginBase {
    
    public function onEnable(): void {
        // Initialize AutoLoad
        AutoLoad::init($this);
        
        // Load commands
        AutoLoad::autoloadCommands("commands");
        
        // Load listeners
        AutoLoad::autoloadListeners("listeners");
    }
}
```

## Usage

### Loading Commands

```php
AutoLoad::autoloadCommands("commands");
```

Automatically discovers and registers all commands in the `commands/` directory.

### Loading Listeners

```php
AutoLoad::autoloadListeners("listeners");
```

Automatically discovers and registers all event listeners in the `listeners/` directory.

### Loading Custom Classes

```php
AutoLoad::autoloadCustom("managers", Manager::class, function(Manager $manager) {
    $manager->initialize();
});
```

Load any custom classes with a callback for initialization.

## Attributes

### Priority Loading

Control the loading order of your classes:

```php
use Valres\AutoLoad\attribute\AutoLoadPriority;

#[AutoLoadPriority(-10)]
class ImportantCommand extends Command {
    // Loaded first
}

#[AutoLoadPriority(10)]
class LessImportantCommand extends Command {
    // Loaded later
}
```

Lower numbers = higher priority (loaded first).

### Cancel AutoLoad

Prevent a class from being autoloaded:

```php
use Valres\AutoLoad\attribute\CancelAutoLoad;

#[CancelAutoLoad]
class DontLoadMe extends Command {
    // This command won't be loaded
}
```

## Directory Structure

```
YourPlugin/
├── src/
│   └── YourNamespace/
│       ├── commands/
│       │   ├── TeleportCommand.php
│       │   └── admin/
│       │       └── BanCommand.php
│       ├── listeners/
│       │   ├── PlayerListener.php
│       │   └── BlockListener.php
│       └── Main.php
```

## Requirements

- PocketMine-MP 5.0+
- PHP 8.1+

## Author

**Valres** - [GitHub](https://github.com/ValresMC)

## License

MIT License
