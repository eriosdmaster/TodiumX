<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\resourcepack;

use raspberry\network\mcpe\protocol\ProtocolInfo;
use raspberry\network\mcpe\protocol\ResourcePacksInfoPacket;
use raspberry\network\mcpe\protocol\ResourcePackStackPacket;
use raspberry\network\mcpe\protocol\ResourcePackClientResponsePacket;
use raspberry\network\mcpe\protocol\ResourcePackDataInfoPacket;
use raspberry\pack\ResourcePack;

use LogicException;
use UnexpectedValueException;

use function array_values;
use function ceil;
use function count;
use function hrtime;
use function in_array;
use function intval;
use function strpos;
use function strtolower;
use function substr;
use function trim;

class ResourcePackNegotiation{

    private const NEW = 0;
    private const WAITING = 1;
    private const WAITING_FOR_STACK = 2;
    private const COMPLETE = 3;

    private const TIMEOUT_NS = 60_000_000_000;
    public const MAX_CHUNK_SIZE = 1048576; // 1 MB

    private int $state = self::NEW;
    private int $deadlineNs = 0;

    /** @var array<string, ResourcePack> packId (uuid or uuid_version) => pack */
    private array $resourcePacksById = [];

    /** @var list<ResourcePack> */
    private array $resourcePacks = [];

    /**
     * @param list<ResourcePack> $resourcePacks active resource packs
     */
    public function __construct(array $resourcePacks = []){
        $this->resourcePacks = array_values($resourcePacks);
        foreach($this->resourcePacks as $pack){
            $this->resourcePacksById[strtolower($pack->getUuid())] = $pack;
            $this->resourcePacksById[strtolower($pack->getUuid() . "_" . $pack->getVersion())] = $pack;
            $this->resourcePacksById[strtolower($pack->getContentId())] = $pack;
        }
    }

    public function begin() : ResourcePacksInfoPacket{
        if($this->state !== self::NEW){
            throw new LogicException("Resource-pack negotiation has already started");
        }

        $this->state = count($this->resourcePacks) > 0 ? self::WAITING : self::WAITING;
        $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;

        $pk = new ResourcePacksInfoPacket();
        $pk->resourcePacks = $this->resourcePacks;
        $pk->mustAccept = false;
        $pk->hasAddons = false;
        $pk->hasScripts = false;
        return $pk;
    }

    public function isComplete() : bool{
        return $this->state === self::COMPLETE;
    }

    public function isExpired(int $nowNs) : bool{
        return $this->state !== self::NEW && $this->state !== self::COMPLETE && $nowNs >= $this->deadlineNs;
    }

    public function hasResourcePacks() : bool{
        return count($this->resourcePacks) > 0;
    }

    public function getResourcePack(string $packId) : ?ResourcePack{
        $key = strtolower(trim($packId));
        if(isset($this->resourcePacksById[$key])){
            return $this->resourcePacksById[$key];
        }
        // try base uuid before underscore
        $base = $key;
        $pos = strpos($key, "_");
        if($pos !== false){
            $base = substr($key, 0, $pos);
        }
        return $this->resourcePacksById[$base] ?? null;
    }

    /**
     * @return list<ResourcePackDataInfoPacket>
     */
    public function createDataInfoPackets(array $packIds) : array{
        $packets = [];
        foreach($packIds as $packId){
            $pack = $this->getResourcePack((string) $packId);
            if($pack === null){
                throw new UnexpectedValueException("Client requested unknown pack: " . $packId);
            }
            $pk = new ResourcePackDataInfoPacket();
            $pk->packId = $pack->getUuid() . "_" . $pack->getVersion();
            $pk->maxChunkSize = self::MAX_CHUNK_SIZE;
            $size = $pack->getPackSize();
            $pk->compressedPackSize = $size;
            $pk->chunkCount = $size <= 0 ? 0 : (int) ceil($size / self::MAX_CHUNK_SIZE);
            $sha = $pack->getSha256(true);
            $pk->sha256 = is_string($sha) ? $sha : "";
            $pk->isPremium = false;
            $pk->packType = ResourcePackDataInfoPacket::TYPE_RESOURCES;
            $packets[] = $pk;
        }
        return $packets;
    }

    public function handle(ResourcePackClientResponsePacket $response) : ?ResourcePackStackPacket{
        if($this->state === self::NEW || $this->state === self::COMPLETE){
            throw new UnexpectedValueException("Resource-pack response arrived outside negotiation");
        }

        if($this->isExpired(hrtime(true))){
            throw new UnexpectedValueException("Resource-pack negotiation timed out");
        }

        if($response->status === ResourcePackClientResponsePacket::CANCEL){
            throw new UnexpectedValueException("Client cancelled resource-pack negotiation");
        }

        switch($response->status){
            case ResourcePackClientResponsePacket::DOWNLOADING:
                // Client wants to download specific packs; NetworkSession will send DataInfo packets.
                // Validate requested IDs are known.
                foreach($response->packIds as $packId){
                    if($this->getResourcePack((string) $packId) === null){
                        throw new UnexpectedValueException("Client requested pack that the server did not advertise: " . $packId);
                    }
                }
                $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;
                return null;

            case ResourcePackClientResponsePacket::DOWNLOADING_FINISHED:
                $this->state = self::WAITING_FOR_STACK;
                $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;
                return $this->createStackPacket();

            case ResourcePackClientResponsePacket::STACK_FINISHED:
                $this->state = self::COMPLETE;
                return null;

            default:
                // Some clients/protocol variants may send other statuses; treat as progress if known.
                if($response->status === 4){ // COMPLETED / HAVE_ALL_PACKS in some versions
                    $this->state = self::WAITING_FOR_STACK;
                    $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;
                    return $this->createStackPacket();
                }
                throw new UnexpectedValueException("Unknown resource-pack response: " . $response->status);
        }
    }

    public function markReadyForValidation() : ?ResourcePackStackPacket{
        if($this->state === self::COMPLETE){
            return null;
        }
        $this->state = self::WAITING_FOR_STACK;
        $this->deadlineNs = hrtime(true) + self::TIMEOUT_NS;
        return $this->createStackPacket();
    }

    public function createStackPacket() : ResourcePackStackPacket{
        $pk = new ResourcePackStackPacket();
        $pk->mustAccept = false;
        $pk->baseGameVersion = ProtocolInfo::MINECRAFT_VERSION_NETWORK;
        $pk->resourcePacks = $this->resourcePacks;
        if(count($this->resourcePacks) > 0){
            $pk->experiments["data_driven_items"] = true;
            $pk->experimentsPreviouslyToggled = true;
        }
        return $pk;
    }
}
