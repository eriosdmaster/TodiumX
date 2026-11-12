<?php

declare(strict_types=1);

namespace raspberry\engine\forms;

use raspberry\engine\Engine;
use raspberry\plugin\Plugin;
use raspberry\Server;
use raspberry\utils\Logger;
use raspberry\player\Player;
use raspberry\form\Form;

class FormsEngine implements Engine {
    private bool $enabled = false;
    private Server $server;
    private Logger $logger;
    
    // Form registries
    private array $simpleForms = [];
    private array $modalForms = [];
    private array $customForms = [];
    
    // Form handlers
    private array $formHandlers = [];
    
    public function __construct(Server $server) {
        $this->server = $server;
        $this->logger = $server->getLogger();
    }
    
    public function getName(): string {
        return "FormsEngine";
    }
    
    public function getVersion(): string {
        return "1.0.0";
    }
    
    public function onLoad(): void {
        $this->logger->info("FormsEngine loaded");
    }
    
    public function onEnable(): void {
        $this->enabled = true;
        $this->logger->info("FormsEngine enabled");
    }
    
    public function onDisable(): void {
        $this->enabled = false;
        $this->logger->info("FormsEngine disabled");
    }
    
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    public function onPluginLoad(Plugin $plugin): void {}
    
    public function onPluginEnable(Plugin $plugin): void {}
    
    public function onPluginDisable(Plugin $plugin): void {}
    
    // ==================== SIMPLE FORMS ====================
    
    public function createSimpleForm(string $title, string $content = ""): SimpleForm {
        return new SimpleForm($title, $content);
    }
    
    public function registerSimpleForm(string $identifier, SimpleForm $form): void {
        $this->simpleForms[$identifier] = $form;
        $this->logger->debug("Registered simple form: $identifier");
    }
    
    public function getSimpleForm(string $identifier): ?SimpleForm {
        return $this->simpleForms[$identifier] ?? null;
    }
    
    public function sendSimpleForm(Player $player, SimpleForm $form, callable $callback): void {
        $formId = $this->generateFormId();
        $this->formHandlers[$formId] = $callback;
        
        $packet = $form->toPacket($formId);
        $player->sendDataPacket($packet);
    }
    
    // ==================== MODAL FORMS ====================
    
    public function createModalForm(string $title, string $content, string $button1, string $button2): ModalForm {
        return new ModalForm($title, $content, $button1, $button2);
    }
    
    public function registerModalForm(string $identifier, ModalForm $form): void {
        $this->modalForms[$identifier] = $form;
        $this->logger->debug("Registered modal form: $identifier");
    }
    
    public function getModalForm(string $identifier): ?ModalForm {
        return $this->modalForms[$identifier] ?? null;
    }
    
    public function sendModalForm(Player $player, ModalForm $form, callable $callback): void {
        $formId = $this->generateFormId();
        $this->formHandlers[$formId] = $callback;
        
        $packet = $form->toPacket($formId);
        $player->sendDataPacket($packet);
    }
    
    // ==================== CUSTOM FORMS ====================
    
    public function createCustomForm(string $title): CustomForm {
        return new CustomForm($title);
    }
    
    public function registerCustomForm(string $identifier, CustomForm $form): void {
        $this->customForms[$identifier] = $form;
        $this->logger->debug("Registered custom form: $identifier");
    }
    
    public function getCustomForm(string $identifier): ?CustomForm {
        return $this->customForms[$identifier] ?? null;
    }
    
    public function sendCustomForm(Player $player, CustomForm $form, callable $callback): void {
        $formId = $this->generateFormId();
        $this->formHandlers[$formId] = $callback;
        
        $packet = $form->toPacket($formId);
        $player->sendDataPacket($packet);
    }
    
    // ==================== FORM HANDLING ====================
    
    public function handleFormResponse(int $formId, Player $player, ?string $data): void {
        if (!isset($this->formHandlers[$formId])) {
            $this->logger->warning("Received response for unknown form ID: $formId");
            return;
        }
        
        $callback = $this->formHandlers[$formId];
        unset($this->formHandlers[$formId]);
        
        try {
            $callback($player, $data);
        } catch (\Throwable $e) {
            $this->logger->error("Form callback error: " . $e->getMessage());
        }
    }
    
    private function generateFormId(): int {
        return mt_rand(100000, 999999);
    }
    
    // ==================== PHP INTEGRATION ====================
    
    public function createFormFromArray(array $definition): ?Form {
        $type = $definition['type'] ?? 'simple';
        
        switch ($type) {
            case 'simple':
                $form = new SimpleForm(
                    $definition['title'] ?? '',
                    $definition['content'] ?? ''
                );
                
                if (isset($definition['buttons'])) {
                    foreach ($definition['buttons'] as $button) {
                        $form->addButton(
                            $button['text'] ?? '',
                            $button['image'] ?? null,
                            $button['image_type'] ?? 'path'
                        );
                    }
                }
                break;
                
            case 'modal':
                $form = new ModalForm(
                    $definition['title'] ?? '',
                    $definition['content'] ?? '',
                    $definition['button1'] ?? 'Confirm',
                    $definition['button2'] ?? 'Cancel'
                );
                break;
                
            case 'custom':
                $form = new CustomForm($definition['title'] ?? '');
                
                if (isset($definition['elements'])) {
                    foreach ($definition['elements'] as $element) {
                        $this->addCustomFormElement($form, $element);
                    }
                }
                break;
                
            default:
                return null;
        }
        
        return $form;
    }
    
    private function addCustomFormElement(CustomForm $form, array $element): void {
        $type = $element['type'] ?? 'label';
        
        switch ($type) {
            case 'label':
                $form->addLabel($element['text'] ?? '');
                break;
            case 'input':
                $form->addInput(
                    $element['text'] ?? '',
                    $element['placeholder'] ?? '',
                    $element['default'] ?? ''
                );
                break;
            case 'textarea':
                $form->addTextArea(
                    $element['text'] ?? '',
                    $element['placeholder'] ?? '',
                    $element['default'] ?? ''
                );
                break;
            case 'dropdown':
                $form->addDropdown(
                    $element['text'] ?? '',
                    $element['options'] ?? [],
                    $element['default'] ?? 0
                );
                break;
            case 'slider':
                $form->addSlider(
                    $element['text'] ?? '',
                    $element['min'] ?? 0,
                    $element['max'] ?? 100,
                    $element['step'] ?? 1,
                    $element['default'] ?? 0
                );
                break;
            case 'toggle':
                $form->addToggle(
                    $element['text'] ?? '',
                    $element['default'] ?? false
                );
                break;
            case 'step_slider':
                $form->addStepSlider(
                    $element['text'] ?? '',
                    $element['steps'] ?? [],
                    $element['default'] ?? 0
                );
                break;
        }
    }
    
    // ==================== PREDEFINED FORMS ====================
    
    public function createConfirmationForm(string $title, string $content, string $confirmText = "Yes", string $cancelText = "No"): ModalForm {
        return new ModalForm($title, $content, $confirmText, $cancelText);
    }
    
    public function createMenuForm(string $title, array $options, string $content = ""): SimpleForm {
        $form = new SimpleForm($title, $content);
        
        foreach ($options as $option) {
            $text = $option['text'] ?? $option;
            $image = $option['image'] ?? null;
            $imageType = $option['image_type'] ?? 'path';
            $form->addButton($text, $image, $imageType);
        }
        
        return $form;
    }
}
