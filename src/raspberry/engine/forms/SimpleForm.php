<?php

declare(strict_types=1);

namespace raspberry\engine\forms;

use raspberry\network\mcpe\protocol\FormRequestPacket;

class SimpleForm extends BaseForm {
    private string $content = "";
    /** @var array<int, array{text: string, image?: string, image_type: string}> */
    private array $buttons = [];
    
    public function __construct(string $title = "", string $content = "") {
        parent::__construct($title);
        $this->content = $content;
    }
    
    public function setContent(string $content): void {
        $this->content = $content;
    }
    
    public function getContent(): string {
        return $this->content;
    }
    
    public function addButton(string $text, ?string $image = null, string $imageType = "path"): void {
        $this->buttons[] = [
            'text' => $text,
            'image' => $image,
            'image_type' => $imageType,
        ];
    }
    
    public function removeButton(int $index): bool {
        if (!isset($this->buttons[$index])) {
            return false;
        }
        
        unset($this->buttons[$index]);
        $this->buttons = array_values($this->buttons);
        return true;
    }
    
    public function getButtons(): array {
        return $this->buttons;
    }
    
    public function getButton(int $index): ?array {
        return $this->buttons[$index] ?? null;
    }
    
    public function clearButtons(): void {
        $this->buttons = [];
    }
    
    public function getType(): string {
        return "form";
    }
    
    public function toPacket(int $formId): FormRequestPacket {
        $this->formId = $formId;
        
        $buttons = [];
        foreach ($this->buttons as $button) {
            $buttonData = [
                "text" => $button['text'],
            ];
            
            if ($button['image'] !== null) {
                $buttonData["image"] = [
                    "type" => $button['image_type'],
                    "data" => $button['image'],
                ];
            }
            
            $buttons[] = $buttonData;
        }
        
        $json = json_encode([
            "type" => "form",
            "title" => $this->title,
            "content" => $this->content,
            "buttons" => $buttons,
        ]);
        
        $packet = new FormRequestPacket();
        $packet->formId = $formId;
        $packet->formData = $json;
        
        return $packet;
    }
}
