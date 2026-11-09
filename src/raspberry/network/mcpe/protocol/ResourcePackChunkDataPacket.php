<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use function strlen;

class ResourcePackChunkDataPacket implements Packet{

    public string $packId = "";
    public int $chunkIndex = 0;
    public int $progress = 0; // offset in bytes
    public string $data = "";

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACK_CHUNK_DATA_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->packId);
        $writer->writeUnsignedInt($this->chunkIndex);
        $writer->writeLong($this->progress);
        $writer->writeUnsignedInt(strlen($this->data));
        $writer->writeBytes($this->data);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->packId = $reader->readString(256);
        $this->chunkIndex = $reader->readUnsignedInt();
        $this->progress = $reader->readLong();
        $length = $reader->readUnsignedInt();
        $this->data = $reader->readBytes($length);
    }
}
