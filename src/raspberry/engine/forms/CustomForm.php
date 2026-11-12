<?php

declare(strict_types=1);

namespace raspberry\engine\forms;

use raspberry\network\mcpe\protocol\FormRequestPacket;

class CustomForm extends BaseForm {
    /** @var array<int, array> */
    private array $elements = [];
    
    public function __construct(string $title = "") {
        parent::__construct($title);
    }
    
    public function addLabel(string $text): void {
        $this->elements[] = [
            "type" => "label",
            "text" => $text,
        ];
    }
    
    public function addInput(string $text, string $placeholder = "", string $default = ""): void {
        $this->elements[] = [
            "type" => "input",
            "text" => $text,
            "placeholder" => $placeholder,
            "default" => $default,
        ];
    }
    
    public function addTextArea(string $text, string $placeholder = "", string $default = ""): void {
        $this->elements[] = [
            "type" => "textarea",
            "text" => $text,
            "placeholder" => $placeholder,
            "default" => $default,
        ];
    }
    
    public function addDropdown(string $text, array $options, int $default = 0): void {
        $this->elements[] = [
            "type" => "dropdown",
            "text" => $text,
            "options" => $options,
            "default" => $default,
        ];
    }
    
    public function addSlider(string $text, int $min = 0, int $max = 100, int $step = 1, int $default = 0): void {
        $this->elements[] = [
            "type" => "slider",
            "text" => $text,
            "min" => $min,
            "max" => $max,
            "step" => $step,
            "default" => $default,
        ];
    }
    
    public function addToggle(string $text, bool $default = false): void {
        $this->elements[] = [
            "type" => "toggle",
            "text" => $text,
            "default" => $default,
        ];
    }
    
    public function addStepSlider(string $text, array $steps, int $default = 0): void {
        $this->elements[] = [
            "type" => "step_slider",
            "text" => $text,
            "steps" => $steps,
            "default" => $default,
        ];
    }
    
    public function removeElement(int $index): bool {
        if (!isset($this->elements[$index])) {
            return false;
        }
        
        unset($this->elements[$index]);
        $this->elements = array_values($this->elements);
        return true;
    }
    
    public function getElements(): array {
        return $this->elements;
    }
    
    public function clearElements(): void {
        $this->elements = [];
    }
    
    public function getType(): string {
        return "custom";
    }
    
    public function toPacket(int $formId): FormRequestPacket {
        $this->formId = $formId;
        
        $json = json_encode([
            "type" => "custom",
            "title" => $this->title,
            "content" => $this->elements,
        ]);
        
        $packet = new FormRequestPacket();
        $packet->formId = $formId;
        $packet->formData = $json;
        
        return $packet;
    }
}
