<?php

declare(strict_types=1);

namespace raspberry\form;

use raspberry\network\mcpe\NetworkSession;

use Throwable;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_int;
use function json_decode;
use function json_last_error;
use function microtime;

class FormManager{

    /** pending forms are dropped after this many seconds without an answer */
    public const PENDING_TIMEOUT_SECONDS = 60.0;

    private NetworkSession $session;

    private int $nextFormId = 0;

    /** @var array<int, array{form: Form, timestamp: float}> */
    private array $pendingForms = [];

    public function __construct(NetworkSession $session){
        $this->session = $session;
    }

    /**
     * Registers and sends a form, returning the generated identifier.
     */
    public function sendForm(Form $form) : int{
        $form->validate();

        $formId = $this->nextFormId++;
        $form->setFormId($formId);

        $this->pendingForms[$formId] = [
            "form" => $form,
            "timestamp" => microtime(true),
        ];

        $this->session->sendFormRequest($formId, $form->toJson());

        return $formId;
    }

    public function hasPendingForm(int $formId) : bool{
        return array_key_exists($formId, $this->pendingForms);
    }

    public function getPendingFormCount() : int{
        return count($this->pendingForms);
    }

    /**
     * Matches a client response with a pending form and dispatches it.
     *
     * @return bool false when the identifier is unknown or already expired
     */
    public function handleResponse(int $formId, ?string $formData, int $cancelReason = 0) : bool{
        $this->expirePendingForms();

        if(!isset($this->pendingForms[$formId])){
            return false;
        }

        $form = $this->pendingForms[$formId]["form"];
        unset($this->pendingForms[$formId]);

        $this->dispatch($form, $formData, $cancelReason);

        return true;
    }

    /**
     * Drops every pending form that has been waiting for too long.
     */
    public function expirePendingForms(float $timeout = self::PENDING_TIMEOUT_SECONDS) : int{
        $now = microtime(true);
        $expired = 0;

        foreach($this->pendingForms as $formId => $entry){
            if(($now - $entry["timestamp"]) > $timeout){
                unset($this->pendingForms[$formId]);
                $expired++;
            }
        }

        return $expired;
    }

    public function clear() : void{
        $this->pendingForms = [];
    }

    private function dispatch(Form $form, ?string $formData, int $cancelReason) : void{
        $callback = $form->getCallback();
        if($callback === null){
            return;
        }

        $decoded = self::decode($formData);

        try{
            switch($form->getType()){
                case SimpleForm::TYPE:
                    $callback($this->resolveIndex($form, $decoded, $cancelReason));
                    break;
                case ModalForm::TYPE:
                    $callback($cancelReason === 0 && is_bool($decoded) && $decoded);
                    break;
                case CustomForm::TYPE:
                    $callback(is_array($decoded) ? $decoded : []);
                    break;
            }
        }catch(Throwable $e){
            // a misbehaving plugin must never take down the server tick
        }
    }

    /**
     * @return mixed decoded value, or null when the response was cancelled or is malformed
     */
    private static function decode(?string $formData){
        if($formData === null || $formData === "" || $formData === "null"){
            return null;
        }

        $decoded = json_decode($formData, true);
        if(json_last_error() !== JSON_ERROR_NONE){
            return null;
        }

        return $decoded;
    }

    /**
     * @param mixed $decoded
     */
    private function resolveIndex(Form $form, $decoded, int $cancelReason) : int{
        if($cancelReason !== 0 || !is_int($decoded) || $decoded < 0){
            return -1;
        }

        if($form instanceof SimpleForm && $decoded >= $form->getButtonCount()){
            return -1;
        }

        return $decoded;
    }
}
