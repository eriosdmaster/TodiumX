<?php

declare(strict_types=1);

namespace raspberry\form;

use UnexpectedValueException;

use function array_values;
use function count;
use function is_array;
use function json_encode;
use function trim;

class CustomForm implements Form{

    public const TYPE = "custom_form";

    private int $formId = 0;

    private string $title;

    /** @var list<array<string, mixed>> */
    private array $elements = [];

    /** @var callable|null */
    private $callback;

    public function __construct(string $title, ?callable $callback = null){
        $this->title = $title;
        $this->callback = $callback;
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

    /**
     * @return list<array<string, mixed>>
     */
    public function getElements() : array{
        return $this->elements;
    }

    public function getElementCount() : int{
        return count($this->elements);
    }

    public function addLabel(string $text) : void{
        $this->elements[] = ["type" => "label", "text" => $text];
    }

    public function addInput(string $text, string $placeholder = "", string $default = "") : void{
        $this->elements[] = [
            "type" => "input",
            "text" => $text,
            "placeholder" => $placeholder,
            "default" => $default,
        ];
    }

    public function addToggle(string $text, bool $default = false) : void{
        $this->elements[] = [
            "type" => "toggle",
            "text" => $text,
            "default" => $default,
        ];
    }

    public function addSlider(string $text, float $min, float $max, float $step = 1.0, ?float $default = null) : void{
        if($step <= 0.0){
            throw new UnexpectedValueException("Slider step must be greater than zero");
        }
        if($min > $max){
            throw new UnexpectedValueException("Slider minimum must not be greater than its maximum");
        }

        $this->elements[] = [
            "type" => "slider",
            "text" => $text,
            "min" => $min,
            "max" => $max,
            "step" => $step,
            "default" => $default ?? $min,
        ];
    }

    public function addStepSlider(string $text, array $steps, ?int $default = null) : void{
        $steps = array_values($steps);
        if($steps === []){
            throw new UnexpectedValueException("Step slider must contain at least one step");
        }

        $default ??= 0;
        if(!isset($steps[$default])){
            throw new UnexpectedValueException("Step slider default index is out of range");
        }

        $this->elements[] = [
            "type" => "step_slider",
            "text" => $text,
            "steps" => $steps,
            "default" => $default,
        ];
    }

    public function addDropdown(string $text, array $options, ?int $default = null) : void{
        $options = array_values($options);
        if($options === []){
            throw new UnexpectedValueException("Dropdown must contain at least one option");
        }

        $default ??= 0;
        if(!isset($options[$default])){
            throw new UnexpectedValueException("Dropdown default index is out of range");
        }

        $this->elements[] = [
            "type" => "dropdown",
            "text" => $text,
            "options" => $options,
            "default" => $default,
        ];
    }

    public function validate() : void{
        if(trim($this->title) === ""){
            throw new UnexpectedValueException("Custom form title must not be empty");
        }

        foreach($this->elements as $element){
            if(!isset($element["text"]) || trim((string) $element["text"]) === ""){
                throw new UnexpectedValueException("Custom form element text must not be empty");
            }

            if($element["type"] === "dropdown" && (!isset($element["options"]) || !is_array($element["options"]) || $element["options"] === [])){
                throw new UnexpectedValueException("Dropdown element must contain at least one option");
            }

            if($element["type"] === "step_slider" && (!isset($element["steps"]) || !is_array($element["steps"]) || $element["steps"] === [])){
                throw new UnexpectedValueException("Step slider element must contain at least one step");
            }
        }
    }

    public function toJson() : string{
        $this->validate();

        return self::encode([
            "type" => self::TYPE,
            "title" => $this->title,
            "content" => $this->elements,
        ]);
    }

    private static function encode(array $data) : string{
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if($json === false){
            throw new UnexpectedValueException("Failed to serialise custom form");
        }
        return $json;
    }
}
