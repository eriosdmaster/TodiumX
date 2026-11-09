<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ResourcePackChunkRequestPacket implements Packet{

    public string $packId = "";
    public int $chunkIndex = 0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACK_CHUNK_REQUEST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->packId);
        $writer->writeUnsignedInt($this->chunkIndex);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->packId = $reader->readString(256);
        $this->chunkIndex = $reader->readUnsignedInt();
    }
}
