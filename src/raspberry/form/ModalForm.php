<?php

declare(strict_types=1);

namespace raspberry\form;

use UnexpectedValueException;

use function json_encode;
use function trim;

class ModalForm implements Form{

    public const TYPE = "modal";

    private int $formId = 0;

    private string $title;

    private ?string $content;

    private string $yesButtonText;

    private string $noButtonText;

    /** @var callable|null */
    private $callback;

    public function __construct(string $title, ?callable $callback = null, ?string $content = null, string $yesButtonText = "Yes", string $noButtonText = "No"){
        $this->title = $title;
        $this->callback = $callback;
        $this->content = $content;
        $this->yesButtonText = $yesButtonText;
        $this->noButtonText = $noButtonText;
    }

    public function getType() : string{
        return self::TYPE;
    }

    public function getFormId() : int{
        return $this->formId;
    }

    public function setFormId(int $formId) : void{
        $this->formId = $formId;
    }

    public function getCallback() : ?callable{
        return $this->callback;
    }

    public function getTitle() : string{
        return $this->title;
    }

    public function getContent() : ?string{
        return $this->content;
    }

    public function getYesButtonText() : string{
        return $this->yesButtonText;
    }

    public function getNoButtonText() : string{
        return $this->noButtonText;
    }

    public function setButtonTexts(string $yes, string $no) : void{
        $this->yesButtonText = $yes;
        $this->noButtonText = $no;
    }

    public function validate() : void{
        if(trim($this->title) === ""){
            throw new UnexpectedValueException("Modal form title must not be empty");
        }

        if(trim($this->yesButtonText) === "" || trim($this->noButtonText) === ""){
            throw new UnexpectedValueException("Modal form button text must not be empty");
        }
    }

    public function toJson() : string{
        $this->validate();

        return self::encode([
            "type" => self::TYPE,
            "title" => $this->title,
            "content" => $this->content ?? "",
            "button1" => $this->yesButtonText,
            "button2" => $this->noButtonText,
        ]);
    }

    private static function encode(array $data) : string{
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if($json === false){
            throw new UnexpectedValueException("Failed to serialise modal form");
        }
        return $json;
    }
}
