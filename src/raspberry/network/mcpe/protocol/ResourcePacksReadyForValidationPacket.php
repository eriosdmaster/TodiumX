<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

/**
 * Sent by the client when it has received pack data and is ready for server-side validation.
 * Protocol 1.26.x (2225).
 */
class ResourcePacksReadyForValidationPacket implements Packet{

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACKS_READY_FOR_VALIDATION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        // client -> server
    }

    public function decodePayload(BinaryReader $reader) : void{
        // no fields in this implementation target; consume nothing / be tolerant
    }
}
