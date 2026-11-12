<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\player\Player;
use raspberry\network\mcpe\protocol\SetScorePacket;
use raspberry\network\mcpe\protocol\SetDisplayObjectivePacket;
use raspberry\network\mcpe\protocol\RemoveObjectivePacket;

class ScoreboardsAPI implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    private array $objectives = [];
    private array $scores = [];
    private array $displays = [];
    private array $teams = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "ScoreboardsAPI";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("ScoreboardsAPI loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("ScoreboardsAPI enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("ScoreboardsAPI disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {
        $this->removePluginObjectives($plugin);
    }
    
    // ==================== OBJECTIVES ====================
    
    public function createObjective(string $name, string $criteria = "dummy", string $displayName = ""): bool {
        if (isset($this->objectives[$name])) {
            return false;
        }
        
        $this->objectives[$name] = [
            'name' => $name,
            'criteria' => $criteria,
            'display_name' => $displayName !== "" ? $displayName : $name,
            'render_type' => 'integer',
        ];
        
        $this->scores[$name] = [];
        $this->logger->info("Created scoreboard objective: $name");
        return true;
    }
    
    public function removeObjective(string $name): bool {
        if (!isset($this->objectives[$name])) {
            return false;
        }
        
        // Remove from displays
        foreach ($this->displays as $slot => $display) {
            if ($display['objective'] === $name) {
                $this->removeDisplay($slot);
            }
        }
        
        unset($this->objectives[$name]);
        unset($this->scores[$name]);
        
        // Send removal packet
        $packet = new RemoveObjectivePacket();
        $packet->objectiveName = $name;
        $this->server->broadcastPacket($packet);
        
        $this->logger->info("Removed scoreboard objective: $name");
        return true;
    }
    
    public function getObjective(string $name): ?array {
        return $this->objectives[$name] ?? null;
    }
    
    public function getAllObjectives(): array {
        return $this->objectives;
    }
    
    public function setObjectiveDisplayName(string $name, string $displayName): bool {
        if (!isset($this->objectives[$name])) {
            return false;
        }
        
        $this->objectives[$name]['display_name'] = $displayName;
        return true;
    }
    
    public function setObjectiveRenderType(string $name, string $renderType): bool {
        if (!isset($this->objectives[$name])) {
            return false;
        }
        
        $validTypes = ['integer', 'hearts'];
        if (!in_array($renderType, $validTypes)) {
            return false;
        }
        
        $this->objectives[$name]['render_type'] = $renderType;
        return true;
    }
    
    // ==================== SCORES ====================
    
    public function setScore(string $objective, string $playerName, int $score): bool {
        if (!isset($this->objectives[$objective])) {
            return false;
        }
        
        $this->scores[$objective][$playerName] = $score;
        
        $packet = new SetScorePacket();
        $packet->objectiveName = $objective;
        $packet->playerName = $playerName;
        $packet->score = $score;
        $packet->type = SetScorePacket::TYPE_CHANGE;
        $this->server->broadcastPacket($packet);
        
        return true;
    }
    
    public function addScore(string $objective, string $playerName, int $amount): int {
        if (!isset($this->objectives[$objective])) {
            return 0;
        }
        
        $current = $this->scores[$objective][$playerName] ?? 0;
        $newScore = $current + $amount;
        $this->setScore($objective, $playerName, $newScore);
        
        return $newScore;
    }
    
    public function removeScore(string $objective, string $playerName): bool {
        if (!isset($this->objectives[$objective])) {
            return false;
        }
        
        unset($this->scores[$objective][$playerName]);
        
        $packet = new SetScorePacket();
        $packet->objectiveName = $objective;
        $packet->playerName = $playerName;
        $packet->score = 0;
        $packet->type = SetScorePacket::TYPE_REMOVE;
        $this->server->broadcastPacket($packet);
        
        return true;
    }
    
    public function getScore(string $objective, string $playerName): int {
        return $this->scores[$objective][$playerName] ?? 0;
    }
    
    public function getAllScores(string $objective): array {
        return $this->scores[$objective] ?? [];
    }
    
    public function getTopScores(string $objective, int $limit = 10): array {
        if (!isset($this->objectives[$objective])) {
            return [];
        }
        
        $scores = $this->scores[$objective];
        arsort($scores);
        
        return array_slice($scores, 0, $limit, true);
    }
    
    // ==================== DISPLAYS ====================
    
    public function setDisplay(string $slot, string $objective): bool {
        $validSlots = ['list', 'sidebar', 'below_name', 'sidebar.team'];
        if (!in_array($slot, $validSlots)) {
            return false;
        }
        
        if (!isset($this->objectives[$objective])) {
            return false;
        }
        
        $this->displays[$slot] = [
            'objective' => $objective,
            'slot' => $slot,
        ];
        
        $packet = new SetDisplayObjectivePacket();
        $packet->displaySlot = $this->getSlotId($slot);
        $packet->objectiveName = $objective;
        $packet->criteriaName = $this->objectives[$objective]['criteria'];
        $packet->sortOrder = 0;
        $this->server->broadcastPacket($packet);
        
        return true;
    }
    
    public function removeDisplay(string $slot): bool {
        if (!isset($this->displays[$slot])) {
            return false;
        }
        
        $objective = $this->displays[$slot]['objective'];
        unset($this->displays[$slot]);
        
        $packet = new SetDisplayObjectivePacket();
        $packet->displaySlot = $this->getSlotId($slot);
        $packet->objectiveName = "";
        $packet->criteriaName = "";
        $packet->sortOrder = 0;
        $this->server->broadcastPacket($packet);
        
        return true;
    }
    
    public function getDisplay(string $slot): ?array {
        return $this->displays[$slot] ?? null;
    }
    
    public function getAllDisplays(): array {
        return $this->displays;
    }
    
    private function getSlotId(string $slot): int {
        return match($slot) {
            'list' => 0,
            'sidebar' => 1,
            'below_name' => 2,
            'sidebar.team' => 3,
            default => 1,
        };
    }
    
    // ==================== TEAMS ====================
    
    public function createTeam(string $name, string $displayName = ""): bool {
        if (isset($this->teams[$name])) {
            return false;
        }
        
        $this->teams[$name] = [
            'name' => $name,
            'display_name' => $displayName !== "" ? $displayName : $name,
            'prefix' => "",
            'suffix' => "",
            'color' => "white",
            'members' => [],
            'friendly_fire' => true,
            'see_invisible' => false,
            'nametag_visibility' => 'always',
            'death_message_visibility' => 'always',
            'collision_rule' => 'always',
        ];
        
        $this->logger->info("Created scoreboard team: $name");
        return true;
    }
    
    public function removeTeam(string $name): bool {
        if (!isset($this->teams[$name])) {
            return false;
        }
        
        unset($this->teams[$name]);
        $this->logger->info("Removed scoreboard team: $name");
        return true;
    }
    
    public function addTeamMember(string $teamName, string $playerName): bool {
        if (!isset($this->teams[$teamName])) {
            return false;
        }
        
        $this->teams[$teamName]['members'][] = $playerName;
        return true;
    }
    
    public function removeTeamMember(string $teamName, string $playerName): bool {
        if (!isset($this->teams[$teamName])) {
            return false;
        }
        
        $this->teams[$teamName]['members'] = array_filter(
            $this->teams[$teamName]['members'],
            fn($m) => $m !== $playerName
        );
        
        return true;
    }
    
    public function getTeam(string $name): ?array {
        return $this->teams[$name] ?? null;
    }
    
    public function getAllTeams(): array {
        return $this->teams;
    }
    
    public function setTeamProperty(string $teamName, string $property, mixed $value): bool {
        if (!isset($this->teams[$teamName])) {
            return false;
        }
        
        $validProperties = ['display_name', 'prefix', 'suffix', 'color', 'friendly_fire', 'see_invisible', 'nametag_visibility', 'death_message_visibility', 'collision_rule'];
        if (!in_array($property, $validProperties)) {
            return false;
        }
        
        $this->teams[$teamName][$property] = $value;
        return true;
    }
    
    private function removePluginObjectives(Plugin $plugin): void {
        // Could track plugin-owned objectives and clean them up
    }
}
