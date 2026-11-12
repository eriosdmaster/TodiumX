<?php

declare(strict_types=1);

namespace raspberry\engine\api;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\player\Player;
use raspberry\engine\forms\FormsEngine;
use raspberry\engine\forms\SimpleForm;
use raspberry\engine\forms\ModalForm;
use raspberry\engine\forms\CustomForm;

class FormsAPI implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    private FormsEngine $formsEngine;
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
        $this->formsEngine = new FormsEngine($server);
    }
    
    public function getName(): string {
        return "FormsAPI";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("FormsAPI loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->formsEngine->onEnable();
        $this->logger->info("FormsAPI enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->formsEngine->onDisable();
        $this->logger->info("FormsAPI disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    // ==================== FORM CREATION ====================
    
    public function createSimpleForm(string $title = "", string $content = ""): SimpleForm {
        return $this->formsEngine->createSimpleForm($title, $content);
    }
    
    public function createModalForm(string $title = "", string $content = "", string $button1 = "Confirm", string $button2 = "Cancel"): ModalForm {
        return $this->formsEngine->createModalForm($title, $content, $button1, $button2);
    }
    
    public function createCustomForm(string $title = ""): CustomForm {
        return $this->formsEngine->createCustomForm($title);
    }
    
    public function createFormFromArray(array $definition): ?\raspberry\form\Form {
        return $this->formsEngine->createFormFromArray($definition);
    }
    
    // ==================== FORM SENDING ====================
    
    public function sendForm(Player $player, \raspberry\form\Form $form, callable $callback): void {
        $this->formsEngine->sendCustomForm($player, $form, $callback);
    }
    
    public function sendSimpleForm(Player $player, SimpleForm $form, callable $callback): void {
        $this->formsEngine->sendSimpleForm($player, $form, $callback);
    }
    
    public function sendModalForm(Player $player, ModalForm $form, callable $callback): void {
        $this->formsEngine->sendModalForm($player, $form, $callback);
    }
    
    public function sendCustomForm(Player $player, CustomForm $form, callable $callback): void {
        $this->formsEngine->sendCustomForm($player, $form, $callback);
    }
    
    // ==================== CONVENIENCE METHODS ====================
    
    public function sendConfirmation(Player $player, string $title, string $content, callable $callback, string $confirmText = "Yes", string $cancelText = "No"): void {
        $form = $this->createModalForm($title, $content, $confirmText, $cancelText);
        $this->sendModalForm($player, $form, function(Player $player, ?string $data) use ($callback) {
            $callback($player, $data === "true" || $data === "1");
        });
    }
    
    public function sendMenu(Player $player, string $title, array $options, callable $callback, string $content = ""): void {
        $form = $this->createSimpleForm($title, $content);
        
        foreach ($options as $index => $option) {
            $text = is_array($option) ? ($option['text'] ?? $option) : $option;
            $image = is_array($option) ? ($option['image'] ?? null) : null;
            $imageType = is_array($option) ? ($option['image_type'] ?? 'path') : 'path';
            $form->addButton($text, $image, $imageType);
        }
        
        $this->sendSimpleForm($player, $form, function(Player $player, ?string $data) use ($callback, $options) {
            if ($data === null) {
                $callback($player, -1);
                return;
            }
            
            $index = (int)$data;
            $callback($player, $index, $options[$index] ?? null);
        });
    }
    
    public function sendInput(Player $player, string $title, string $text, string $placeholder = "", callable $callback, string $default = ""): void {
        $form = $this->createCustomForm($title);
        $form->addInput($text, $placeholder, $default);
        
        $this->sendCustomForm($player, $form, function(Player $player, ?string $data) use ($callback) {
            if ($data === null) {
                $callback($player, null);
                return;
            }
            
            $response = json_decode($data, true);
            $callback($player, $response[0] ?? null);
        });
    }
    
    public function sendDropdown(Player $player, string $title, string $text, array $options, callable $callback, int $default = 0): void {
        $form = $this->createCustomForm($title);
        $form->addDropdown($text, $options, $default);
        
        $this->sendCustomForm($player, $form, function(Player $player, ?string $data) use ($callback, $options) {
            if ($data === null) {
                $callback($player, -1, null);
                return;
            }
            
            $response = json_decode($data, true);
            $index = $response[0] ?? -1;
            $callback($player, $index, $options[$index] ?? null);
        });
    }
    
    public function sendSlider(Player $player, string $title, string $text, int $min, int $max, int $step, callable $callback, int $default = 0): void {
        $form = $this->createCustomForm($title);
        $form->addSlider($text, $min, $max, $step, $default);
        
        $this->sendCustomForm($player, $form, function(Player $player, ?string $data) use ($callback) {
            if ($data === null) {
                $callback($player, null);
                return;
            }
            
            $response = json_decode($data, true);
            $callback($player, $response[0] ?? null);
        });
    }
    
    public function sendToggle(Player $player, string $title, string $text, callable $callback, bool $default = false): void {
        $form = $this->createCustomForm($title);
        $form->addToggle($text, $default);
        
        $this->sendCustomForm($player, $form, function(Player $player, ?string $data) use ($callback) {
            if ($data === null) {
                $callback($player, null);
                return;
            }
            
            $response = json_decode($data, true);
            $callback($player, $response[0] === true || $response[0] === "1" || $response[0] === 1);
        });
    }
    
    // ==================== FORM REGISTRY ====================
    
    public function registerForm(string $identifier, \raspberry\form\Form $form): void {
        if ($form instanceof SimpleForm) {
            $this->formsEngine->registerSimpleForm($identifier, $form);
        } elseif ($form instanceof ModalForm) {
            $this->formsEngine->registerModalForm($identifier, $form);
        } elseif ($form instanceof CustomForm) {
            $this->formsEngine->registerCustomForm($identifier, $form);
        }
    }
    
    public function getForm(string $identifier): ?\raspberry\form\Form {
        $form = $this->formsEngine->getSimpleForm($identifier);
        if ($form !== null) return $form;
        
        $form = $this->formsEngine->getModalForm($identifier);
        if ($form !== null) return $form;
        
        return $this->formsEngine->getCustomForm($identifier);
    }
    
    public function sendRegisteredForm(Player $player, string $identifier, callable $callback): bool {
        $form = $this->getForm($identifier);
        if ($form === null) {
            return false;
        }
        
        $this->sendForm($player, $form, $callback);
        return true;
    }
}
