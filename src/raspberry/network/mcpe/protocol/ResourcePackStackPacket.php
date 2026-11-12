<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\pack\ResourcePack;

use UnexpectedValueException;

use function count;

class ResourcePackStackPacket implements Packet{

    public string $baseGameVersion = ProtocolInfo::MINECRAFT_VERSION_NETWORK;

    public bool $mustAccept = false;

    /** @var list<ResourcePack> */
    public array $resourcePacks = [];

    /** @var array<string, bool> experiment name => enabled */
    public array $experiments = [];

    public bool $experimentsPreviouslyToggled = false;
    public bool $hasEditorPacks = false;

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACK_STACK_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeBool($this->mustAccept);

        // Resource pack stack
        $writer->writeUnsignedVarInt(count($this->resourcePacks));
        foreach($this->resourcePacks as $pack){
            $m = $pack->getManifest();
            $writer->writeString($m->getUuid());
            $writer->writeString($m->getVersion());
            $writer->writeString(""); // sub-pack name
        }

        $writer->writeString($this->baseGameVersion, 64);

        $writer->writeUnsignedInt(count($this->experiments));
        foreach($this->experiments as $name => $enabled){
            $writer->writeString($name);
            $writer->writeBool($enabled);
        }
        $writer->writeBool($this->experimentsPreviouslyToggled);
        $writer->writeBool($this->hasEditorPacks);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ResourcePackStackPacket is server-to-client only");
    }
}
