<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class ResourcePackDataInfoPacket implements Packet{

    public const TYPE_INVALID = 0;
    public const TYPE_ADDON = 1;
    public const TYPE_CACHED = 2;
    public const TYPE_COPY_PROTECTED = 3;
    public const TYPE_BEHAVIOR = 4;
    public const TYPE_PERSONA_PIECE = 5;
    public const TYPE_RESOURCES = 6;
    public const TYPE_SKINS = 7;
    public const TYPE_WORLD_TEMPLATE = 8;

    public string $packId = "";
    public int $maxChunkSize = 1048576; // 1MB
    public int $chunkCount = 0;
    public int $compressedPackSize = 0;
    public string $sha256 = "";
    public bool $isPremium = false;
    public int $packType = self::TYPE_RESOURCES;

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACK_DATA_INFO_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->packId);
        $writer->writeUnsignedInt($this->maxChunkSize);
        $writer->writeUnsignedInt($this->chunkCount);
        $writer->writeLong($this->compressedPackSize);
        $writer->writeString($this->sha256);
        $writer->writeBool($this->isPremium);
        $writer->writeByte($this->packType);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->packId = $reader->readString(256);
        $this->maxChunkSize = $reader->readUnsignedInt();
        $this->chunkCount = $reader->readUnsignedInt();
        $this->compressedPackSize = $reader->readLong();
        $this->sha256 = $reader->readString(256);
        $this->isPremium = $reader->readBool();
        $this->packType = $reader->readByte();
    }
}
