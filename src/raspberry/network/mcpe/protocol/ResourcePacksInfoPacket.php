<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\pack\BehaviorPack;
use raspberry\pack\ResourcePack;

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

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeBool($this->mustAccept);
        $writer->writeBool($this->hasAddons);
        $writer->writeBool($this->hasScripts);
        $writer->writeBool($this->forceDisableVibrantVisuals);

        // world template UUID (16 zero bytes) + version
        $writer->writeBytes(str_repeat("\x00", 16));
        $writer->writeString("");

        // Resource pack entries
        // Protocol 2225 (1.26.60): entry format is best-effort and may need adjustment with a real client.
        $writer->writeUnsignedShort(count($this->resourcePacks));
        foreach($this->resourcePacks as $pack){
            $this->writeResourcePackEntry($writer, $pack);
        }

        // The server does not advertise behavior packs for client download in this implementation;
        // behavior packs are applied server-side. Keep count 0 for compatibility.
        $writer->writeUnsignedShort(0);
    }

    private function writeResourcePackEntry(BinaryWriter $writer, ResourcePack $pack) : void{
        $manifest = $pack->getManifest();
        $uuid = $manifest->getUuid();
        $version = $manifest->getVersion();
        $size = $pack->getPackSize();
        $contentId = $pack->getContentId();
        $hasScripts = $manifest->hasScripts();

        $writer->writeString($uuid);
        $writer->writeString($version);
        $writer->writeLong($size);
        $writer->writeString(""); // content key / encryption key
        $writer->writeString(""); // sub-pack name
        $writer->writeString($contentId); // content identity
        $writer->writeBool($hasScripts);
        $writer->writeBool(false); // is addon pack
        $writer->writeBool(false); // is ray tracing capable / rtx
        $writer->writeString(""); // CDN URL
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ResourcePacksInfoPacket is server-to-client only");
    }
}
