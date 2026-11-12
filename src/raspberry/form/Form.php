<?php

declare(strict_types=1);

namespace raspberry\form;

interface Form{

    /**
     * The protocol "type" field of this form (
     * "form", "modal" or "custom_form").
     */
    public function getType() : string;

    /**
     * Identifier used to match this form with the response sent back by the client.
     */
    public function getFormId() : int;

    public function setFormId(int $formId) : void;

    /**
     * Callback invoked with the native response once the client answers the form.
     */
    public function getCallback() : ?callable;

    /**
     * Throws when the form is not safe to serialise (empty title, no buttons, ...).
     */
    public function validate() : void;

    public function toJson() : string;
}
