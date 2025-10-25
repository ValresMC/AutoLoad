<?php

declare(strict_types=1);

namespace Valres\AutoLoad;

use InvalidArgumentException;
use pocketmine\command\Command;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use ReflectionClass;
use ReflectionException;
use Valres\AutoLoad\attribute\AutoLoadPriority;
use Valres\AutoLoad\attribute\CancelAutoLoad;
use RuntimeException;
use Throwable;

/**
 * AutoLoad - Automatic class loading system for PocketMine-MP plugins
 *
 * This class provides automatic discovery and registration of Commands, Listeners,
 * and custom classes from specified directories. It supports priority-based loading
 * and provides a summary of loaded components.
 *
 * @author  Root
 * @version 1.0.0
 */
final class AutoLoad
{
    private static ?PluginBase $plugin = null;
    private static string $pluginSrcPath = "";
    private static array $loadedClasses = [];
    private static array $loadedCount = [
        "command"  => 0,
        "listener" => 0,
        "custom"   => 0
    ];

    private static bool $initialized = false;
    private static int $loadingCounter = 0;

    private static bool $printSummary = false;
    private static bool $summaryPrinted = false;

    /**
     * Private constructor to prevent instantiation
     * AutoLoad is designed to be used statically only
     */
    private function __construct() {}

    /**
     * Initialize the AutoLoad system with a plugin instance
     *
     * This must be called before any autoloading operations.
     * Typically called in the plugin's onEnable() method.
     *
     * @param  PluginBase $plugin
     * @param  bool $printSummary
     *
     * @throws ReflectionException
     */
    public static function init(PluginBase $plugin, bool $printSummary = true): void {
        if (self::$plugin !== null) {
            throw new RuntimeException("AutoLoad is already initialized.");
        }

        $rc = new ReflectionClass($plugin);

        self::$plugin        = $plugin;
        self::$pluginSrcPath = rtrim($rc->getMethod("getFile")->invoke($plugin), "/\\") . "/src/";
        self::$initialized   = true;

        self::$printSummary = $printSummary;
    }

    /**
     * Ensure AutoLoad has been initialized before use
     *
     * @throws RuntimeException If AutoLoad has not been initialized
     */
    private static function ensureInit(): void {
        if (!self::$initialized || self::$plugin === null) {
            throw new RuntimeException("AutoLoad must be initialized before use. Call AutoLoad::init(\$plugin).");
        }
    }

    /**
     * Sanitize and normalize a directory path
     *
     * Converts directory separators to forward slashes and removes trailing slashes.
     * Also validates that the path doesn't contain directory traversal sequences.
     *
     * @param  string $directory
     * @return string
     * @throws InvalidArgumentException
     */
    private static function sanitizeDirectory(string $directory): string {
        $normalized = rtrim(str_replace(DIRECTORY_SEPARATOR, "/", $directory), "/");
        if (str_contains($normalized, "..")) {
            throw new InvalidArgumentException("Directory path cannot contain '..'");
        }
        return $normalized;
    }

    /**
     * Recursively scan a directory and call a callback for each PHP class found
     *
     * This method walks through the directory structure, discovers PHP files,
     * constructs their full namespace paths, and invokes the callback for each.
     *
     * @param string   $directory
     * @param callable $callback
     *
     * @throws RuntimeException
     */
    private static function callDirectory(string $directory, callable $callback): void {
        self::ensureInit();

        $plugin = self::$plugin;
        $mainParts = explode("\\", $plugin->getDescription()->getMain());
        array_pop($mainParts);
        $mainNamespace = implode("\\", $mainParts);
        $mainPath = implode("/", $mainParts);

        $normalizedDir = self::sanitizeDirectory($directory);
        $baseDir = self::$pluginSrcPath . "{$mainPath}/{$normalizedDir}";

        if (!is_dir($baseDir)) {
            throw new RuntimeException("Directory not found: {$baseDir}");
        }

        foreach (array_diff(scandir($baseDir) ?: [], [".", ".."]) as $file) {
            $path = "{$baseDir}/{$file}";
            if (is_dir($path)) {
                self::callDirectory("{$normalizedDir}/{$file}", $callback);
                continue;
            }
            if (pathinfo($path, PATHINFO_EXTENSION) !== "php") {
                continue;
            }

            $relativeNamespace = str_replace("/", "\\", $normalizedDir);
            $className = basename($file, ".php");
            $namespace = "{$mainNamespace}\\{$relativeNamespace}\\{$className}";

            try {
                $callback($namespace);
            } catch (Throwable $e) {
                $plugin->getLogger()->error("Error loading {$file}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Generic autoloading method for any class type
     *
     * This method handles the core autoloading logic:
     * 1. Discovers classes in the specified directory
     * 2. Filters by required type (class/interface)
     * 3. Respects #[CancelAutoLoad] attribute
     * 4. Sorts by priority using #[AutoLoadPriority] attribute
     * 5. Instantiates and registers each class via callback
     * 6. Tracks loading progress and displays summary when complete
     *
     * @param string                       $filePath
     * @param string                       $requiredType
     * @param callable(object,string):void $registerCallback
     * @param string                       $typeName
     *
     * @internal
     */
    private static function autoloadType(string $filePath, string $requiredType, callable $registerCallback, string $typeName): void {
        self::$loadingCounter++;
        $classes = [];

        self::callDirectory($filePath, function(string $namespace) use (&$classes, $requiredType): void {
            if (!class_exists($namespace)) {
                return;
            }

            try {
                $reflection = new ReflectionClass($namespace);
            } catch (\ReflectionException) {
                return;
            }

            if (!empty($reflection->getAttributes(CancelAutoLoad::class))) {
                return;
            }

            if (!$reflection->isInstantiable()) {
                return;
            }

            $isValid = $reflection->isSubclassOf($requiredType) || $reflection->implementsInterface($requiredType);
            if (!$isValid) {
                return;
            }

            $attr     = $reflection->getAttributes(AutoLoadPriority::class);
            $priority = !empty($attr) ? $attr[0]->newInstance()->priority : 0;

            $classes[] = [
                "namespace" => $namespace,
                "reflection" => $reflection,
                "priority" => $priority
            ];
        });

        usort($classes, static fn($a, $b) => $a["priority"] <=> $b["priority"]);

        foreach ($classes as $entry) {
            $namespace = $entry["namespace"];

            if (isset(self::$loadedClasses[$namespace])) {
                continue;
            }

            try {
                $instance = $entry["reflection"]->newInstance();
                $registerCallback($instance, $namespace);
                self::$loadedClasses[$namespace] = true;
                self::$loadedCount[$typeName]++;
            } catch (Throwable $e) {
                self::$plugin->getLogger()->error("Failed to instantiate {$typeName} {$namespace}: {$e->getMessage()}");
            }
        }

        self::$loadingCounter--;
        if (self::$printSummary) {
            self::tryPrintSummary();
        }
    }

    /**
     * Automatically load and register all commands from a directory
     *
     * Scans the specified directory for classes extending Command,
     * instantiates them, and registers them with the server's CommandMap.
     *
     * Supports:
     * - #[AutoLoadPriority(int)] to control loading order
     * - #[CancelAutoLoad] to skip specific commands
     *
     * @param  string $filePath
     * @throws RuntimeException
     */
    public static function autoloadCommands(string $filePath): void {
        self::ensureInit();
        self::autoloadType($filePath, Command::class, function(Command $cmd): void {
            self::$plugin->getServer()->getCommandMap()->register("auto-load", $cmd);
        }, "command");
    }

    /**
     * Automatically load and register all event listeners from a directory
     *
     * Scans the specified directory for classes implementing Listener,
     * instantiates them, and registers them with the server's PluginManager.
     *
     * Supports:
     * - #[AutoLoadPriority(int)] to control loading order
     * - #[CancelAutoLoad] to skip specific listeners
     *
     * @param  string $filePath
     * @throws RuntimeException
     */
    public static function autoloadListeners(string $filePath): void {
        self::ensureInit();
        self::autoloadType($filePath, Listener::class, function(Listener $listener): void {
            self::$plugin->getServer()->getPluginManager()->registerEvents($listener, self::$plugin);
        }, "listener");
    }

    /**
     * Automatically load custom classes with a specified callback
     *
     * Scans the specified directory for classes extending/implementing the required type,
     * instantiates them, and passes them to your custom callback for registration.
     *
     * This is useful for loading custom components like:
     * - Manager classes
     * - Service providers
     * - Task schedulers
     * - Database models
     * - API handlers
     *
     * Supports:
     * - #[AutoLoadPriority(int)] to control loading order
     * - #[CancelAutoLoad] to skip specific classes
     *
     * @param  string                       $filePath
     * @param  string                       $requiredType
     * @param  callable(object,string):void $callback
     *
     * @throws RuntimeException
     */
    public static function autoloadCustom(string $filePath, string $requiredType, callable $callback): void {
        self::ensureInit();
        self::autoloadType($filePath, $requiredType, $callback, "custom");
    }

    /**
     * Attempt to print the loading summary
     *
     * The summary is only printed when:
     * 1. All loading operations have completed (loadingCounter == 0)
     * 2. The summary hasn't been printed yet
     *
     * This ensures the summary appears only once after all autoloading is done.
     *
     * @internal
     */
    private static function tryPrintSummary(): void {
        if (self::$summaryPrinted || self::$loadingCounter > 0) {
            return;
        }

        self::$summaryPrinted = true;
        $logger = self::$plugin->getLogger();

        $logger->info("§8---------------------------");
        $logger->info("§7 Chargement terminé pour §e" . self::$plugin->getName());
        $logger->info("§7 » §f" . self::$loadedCount["command"]  . " §7Commandes");
        $logger->info("§7 » §f" . self::$loadedCount["listener"] . " §7Listeners");
        $logger->info("§7 » §f" . self::$loadedCount["custom"]   . " §7Éléments personnalisés");
        $logger->info("§8---------------- by Root --");
    }
}