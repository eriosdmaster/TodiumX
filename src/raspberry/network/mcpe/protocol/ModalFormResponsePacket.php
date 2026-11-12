<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function strlen;

class ModalFormResponsePacket implements Packet{

    /** responses larger than this are refused without being read */
    public const MAX_FORM_DATA_BYTES = 2 * 1024 * 1024;

    public int $formId = 0;

    /** null when the client cancelled the form or sent no data at all */
    public ?string $formData = null;

    public int $cancelReason = 0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::MODAL_FORM_RESPONSE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt($this->formId);

        $data = $this->formData ?? "";
        self::checkLength(strlen($data));

        $writer->writeString($data, self::MAX_FORM_DATA_BYTES);
        $writer->writeUnsignedByte($this->cancelReason);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->formId = $reader->readUnsignedVarInt();

        $data = $reader->readString(self::MAX_FORM_DATA_BYTES);
        self::checkLength(strlen($data));

        // a closed form arrives as an empty string or as the literal "null"
        $this->formData = ($data === "" || $data === "null") ? null : $data;
        $this->cancelReason = $reader->readUnsignedByte();
    }

    private static function checkLength(int $length) : void{
        if($length > self::MAX_FORM_DATA_BYTES){
            throw new UnexpectedValueException("Modal form response data length is outside the permitted range");
        }
    }
}
