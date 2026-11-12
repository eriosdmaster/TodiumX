<?php

declare(strict_types=1);

namespace raspberry\form;

use UnexpectedValueException;

use function count;
use function is_array;
use function json_encode;
use function trim;

class SimpleForm implements Form{

    public const TYPE = "form";

    /** used when the caller does not supply its own buttons */
    private const DEFAULT_BUTTONS = [
        ["text" => "Yes", "image" => null],
        ["text" => "No", "image" => null],
    ];

    private int $formId = 0;

    private string $title;

    private ?string $content;

    /** @var list<array{text: string, image: ?string}> */
    private array $buttons;

    /** @var callable|null */
    private $callback;

    public function __construct(string $title, ?callable $callback = null, ?string $content = null, array $buttons = []){
        $this->title = $title;
        $this->callback = $callback;
        $this->content = $content;
        $this->buttons = $buttons === [] ? self::DEFAULT_BUTTONS : self::normalizeButtons($buttons);
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

    /**
     * @return list<array{text: string, image: ?string}>
     */
    public function getButtons() : array{
        return $this->buttons;
    }

    public function getButtonCount() : int{
        return count($this->buttons);
    }

    public function addButton(string $text, ?string $image = null) : void{
        $this->buttons[] = ["text" => $text, "image" => $image];
    }

    public function validate() : void{
        if(trim($this->title) === ""){
            throw new UnexpectedValueException("Simple form title must not be empty");
        }

        if($this->buttons === []){
            throw new UnexpectedValueException("Simple form must contain at least one button");
        }

        foreach($this->buttons as $button){
            if(trim($button["text"]) === ""){
                throw new UnexpectedValueException("Simple form button text must not be empty");
            }
        }
    }

    public function toJson() : string{
        $this->validate();

        $buttons = [];
        foreach($this->buttons as $button){
            $entry = ["text" => $button["text"]];
            if($button["image"] !== null){
                $entry["image"] = $button["image"];
            }
            $buttons[] = $entry;
        }

        return self::encode([
            "type" => self::TYPE,
            "title" => $this->title,
            "content" => $this->content ?? "",
            "buttons" => $buttons,
        ]);
    }

    /**
     * @param list<array{text?: string, image?: ?string}|string> $buttons
     * @return list<array{text: string, image: ?string}>
     */
    private static function normalizeButtons(array $buttons) : array{
        $result = [];
        foreach($buttons as $button){
            if(is_array($button)){
                $result[] = [
                    "text" => (string) ($button["text"] ?? ""),
                    "image" => isset($button["image"]) ? (string) $button["image"] : null,
                ];
            }else{
                $result[] = ["text" => (string) $button, "image" => null];
            }
        }
        return $result;
    }

    private static function encode(array $data) : string{
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if($json === false){
            throw new UnexpectedValueException("Failed to serialise simple form");
        }
        return $json;
    }
}
