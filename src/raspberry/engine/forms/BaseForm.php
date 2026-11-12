<?php

declare(strict_types=1);

namespace raspberry\engine\forms;

use raspberry\form\Form;
use raspberry\network\mcpe\protocol\FormRequestPacket;

abstract class BaseForm {
    protected string $title = "";
    protected int $formId = 0;
    
    public function __construct(string $title = "") {
        $this->title = $title;
    }
    
    public function setTitle(string $title): void {
        $this->title = $title;
    }
    
    public function getTitle(): string {
        return $this->title;
    }
    
    public function setFormId(int $formId): void {
        $this->formId = $formId;
    }
    
    public function getFormId(): int {
        return $this->formId;
    }
    
    abstract public function toPacket(int $formId): FormRequestPacket;
    abstract public function getType(): string;
}
