<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\pack\BehaviorPack;
use raspberry\pack\ResourcePack;
use raspberry\utils\Uuid;

use UnexpectedValueException;

use function count;
use function str_repeat;

class ResourcePacksInfoPacket implements Packet{

    public bool $mustAccept = false;
    public bool $hasAddons = false;
    public bool $hasScripts = false;
    public bool $forceDisableVibrantVisuals = false;

    /** @var list<ResourcePack> */
    public array $resourcePacks = [];

    /** @var list<BehaviorPack> */
    public array $behaviorPacks = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACKS_INFO_PACKET;
    }

    /**
     * Layout from Mojang's protocol docs (protocol 2225):
     * required, hasAddons, hasScripts, forceDisableVibrantVisuals, PackIdVersion (world template UUID + version),
     * array<PackInfoData>. There is no behavior pack list any more, and pack UUIDs are binary (two uint64).
     */
    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeBool($this->mustAccept);
        $writer->writeBool($this->hasAddons);
        $writer->writeBool($this->hasScripts);
        $writer->writeBool($this->forceDisableVibrantVisuals);

        // world template: PackIdVersion = UUID (2x uint64) + version string
        $writer->writeBytes(str_repeat("\x00", 16));
        $writer->writeString("");

        $writer->writeUnsignedVarInt(count($this->resourcePacks));
        foreach($this->resourcePacks as $pack){
            $this->writeResourcePackEntry($writer, $pack);
        }
    }

    private function writeResourcePackEntry(BinaryWriter $writer, ResourcePack $pack) : void{
        $manifest = $pack->getManifest();

        // PackIdVersion
        $writer->writeUuid(Uuid::fromString($manifest->getUuid()));
        $writer->writeString($manifest->getVersion());

        $writer->writeLong($pack->getPackSize());
        $writer->writeString("");                    // content key
        $writer->writeString("");                    // sub-pack name
        $writer->writeString($pack->getContentId()); // content identity
        $writer->writeBool($manifest->hasScripts());
        $writer->writeBool(false);                   // is addon pack
        $writer->writeBool(false);                   // is ray tracing capable
        $writer->writeString("");                    // CDN URL
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ResourcePacksInfoPacket is server-to-client only");
    }
}
