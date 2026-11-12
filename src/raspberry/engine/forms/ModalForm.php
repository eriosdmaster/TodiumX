<?php

declare(strict_types=1);

namespace raspberry\engine\forms;

use raspberry\network\mcpe\protocol\FormRequestPacket;

class ModalForm extends BaseForm {
    private string $content = "";
    private string $button1 = "Confirm";
    private string $button2 = "Cancel";
    
    public function __construct(string $title = "", string $content = "", string $button1 = "Confirm", string $button2 = "Cancel") {
        parent::__construct($title);
        $this->content = $content;
        $this->button1 = $button1;
        $this->button2 = $button2;
    }
    
    public function setContent(string $content): void {
        $this->content = $content;
    }
    
    public function getContent(): string {
        return $this->content;
    }
    
    public function setButton1(string $text): void {
        $this->button1 = $text;
    }
    
    public function getButton1(): string {
        return $this->button1;
    }
    
    public function setButton2(string $text): void {
        $this->button2 = $text;
    }
    
    public function getButton2(): string {
        return $this->button2;
    }
    
    public function getType(): string {
        return "modal";
    }
    
    public function toPacket(int $formId): FormRequestPacket {
        $this->formId = $formId;
        
        $json = json_encode([
            "type" => "modal",
            "title" => $this->title,
            "content" => $this->content,
            "button1" => $this->button1,
            "button2" => $this->button2,
        ]);
        
        $packet = new FormRequestPacket();
        $packet->formId = $formId;
        $packet->formData = $json;
        
        return $packet;
    }
}
