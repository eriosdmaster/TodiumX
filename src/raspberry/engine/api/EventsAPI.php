<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\event\Event;
use raspberry\event\Listener;
use Closure;

class EventsAPI implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    private array $customEvents = [];
    private array $eventListeners = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "EventsAPI";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("EventsAPI loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("EventsAPI enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("EventsAPI disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {
        $this->unregisterPluginListeners($plugin);
    }
    
    // ==================== CUSTOM EVENTS ====================
    
    public function registerEvent(string $eventName, string $eventClass): void {
        $this->customEvents[$eventName] = $eventClass;
        $this->logger->info("Registered custom event: $eventName");
    }
    
    public function unregisterEvent(string $eventName): bool {
        if (!isset($this->customEvents[$eventName])) {
            return false;
        }
        
        unset($this->customEvents[$eventName]);
        $this->logger->info("Unregistered custom event: $eventName");
        return true;
    }
    
    public function getEventClass(string $eventName): ?string {
        return $this->customEvents[$eventName] ?? null;
    }
    
    // ==================== EVENT FIRING ====================
    
    public function fireEvent(string $eventName, array $data = []): ?Event {
        $eventClass = $this->getEventClass($eventName);
        if ($eventClass === null) {
            return null;
        }
        
        if (!class_exists($eventClass)) {
            $this->logger->error("Event class '$eventClass' not found for event '$eventName'");
            return null;
        }
        
        $event = new $eventClass($data);
        $this->server->getPluginManager()->callEvent($event);
        
        return $event;
    }
    
    public function fireEventObject(Event $event): void {
        $this->server->getPluginManager()->callEvent($event);
    }
    
    // ==================== LISTENER REGISTRATION ====================
    
    public function registerListener(Plugin $plugin, Listener $listener, int $priority = 0): void {
        $pluginName = $plugin->getName();
        
        if (!isset($this->eventListeners[$pluginName])) {
            $this->eventListeners[$pluginName] = [];
        }
        
        $this->eventListeners[$pluginName][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];
        
        $this->server->getPluginManager()->registerEvents($listener, $plugin);
    }
    
    public function registerListenerWithPriority(Plugin $plugin, Listener $listener, string $eventClass, int $priority): void {
        // This would use reflection to register specific event handlers with priority
        $this->server->getPluginManager()->registerEvents($listener, $plugin);
    }
    
    public function unregisterListener(Plugin $plugin, Listener $listener): void {
        $pluginName = $plugin->getName();
        
        if (!isset($this->eventListeners[$pluginName])) {
            return;
        }
        
        $this->eventListeners[$pluginName] = array_filter(
            $this->eventListeners[$pluginName],
            fn($l) => $l['listener'] !== $listener
        );
        
        // Note: PluginManager doesn't have unregisterEvents, so we can't fully unregister
    }
    
    public function unregisterPluginListeners(Plugin $plugin): void {
        $pluginName = $plugin->getName();
        
        if (!isset($this->eventListeners[$pluginName])) {
            return;
        }
        
        foreach ($this->eventListeners[$pluginName] as $listenerData) {
            // Can't fully unregister from PluginManager
        }
        
        unset($this->eventListeners[$pluginName]);
    }
    
    // ==================== EVENT HELPERS ====================
    
    public function createEvent(string $eventName, array $data = []): ?Event {
        $eventClass = $this->getEventClass($eventName);
        if ($eventClass === null || !class_exists($eventClass)) {
            return null;
        }
        
        return new $eventClass($data);
    }
    
    public function isEventRegistered(string $eventName): bool {
        return isset($this->customEvents[$eventName]);
    }
    
    public function getRegisteredEvents(): array {
        return array_keys($this->customEvents);
    }
    
    public function getPluginListeners(string $pluginName): array {
        return $this->eventListeners[$pluginName] ?? [];
    }
}
