<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function strlen;

class ModalFormRequestPacket implements Packet{

    /** forms larger than this are refused without being read */
    public const MAX_FORM_DATA_BYTES = 2 * 1024 * 1024;

    public int $formId = 0;
    public string $formData = "";

    public static function getPacketId() : int{
        return ProtocolPacketIds::MODAL_FORM_REQUEST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        self::checkLength(strlen($this->formData));

        $writer->writeUnsignedVarInt($this->formId);
        $writer->writeString($this->formData, self::MAX_FORM_DATA_BYTES);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->formId = $reader->readUnsignedVarInt();
        $this->formData = $reader->readString(self::MAX_FORM_DATA_BYTES);

        self::checkLength(strlen($this->formData));
    }

    private static function checkLength(int $length) : void{
        if($length < 1 || $length > self::MAX_FORM_DATA_BYTES){
            throw new UnexpectedValueException("Modal form data length is outside the permitted range");
        }
    }
}
