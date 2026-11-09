<?php

declare(strict_types=1);

namespace raspberry\network\nethernet;

use pmmp\webrtc\ConnectionState;
use pmmp\webrtc\DataChannel;
use pmmp\webrtc\IceCandidate;
use pmmp\webrtc\PeerConnection;
use pmmp\webrtc\PeerConnectionOptions;
use pmmp\webrtc\GatheringState;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class WebRtcSession{

    private const MAX_MESSAGE_BYTES = 262144;
    private const MAX_BUFFERED_BYTES = 4194304;
    private const MAX_MESSAGES_PER_POLL = 64;

    private readonly PeerConnection $peer;
    private readonly int $createdAt;

    /** @var array<string, DataChannel> */
    private array $channels = [];

    private bool $closed = false;
    private bool $answerEmitted = false;

    public function __construct(
        private readonly int $id,
        string $remoteOffer
    ){
        if($remoteOffer === "" || strlen($remoteOffer) > 65536){
            throw new InvalidArgumentException("Invalid SDP offer size");
        }

        $options = PeerConnectionOptions::create()
            ->setMaxMessageSize(self::MAX_MESSAGE_BYTES)
            ->setMaxReceiveQueueSize(self::MAX_BUFFERED_BYTES)
            ->setMaxReceiveQueueMessages(256)
            ->setMaxSendQueueSize(self::MAX_BUFFERED_BYTES)
            ->setMaxRemoteDescriptionSize(65536)
            ->setMaxPendingDataChannels(2);

        $options = self::applyIce($options);

        $this->createdAt = hrtime(true);
        $this->peer = new PeerConnection($options);

        try{
            $this->peer->setRemoteOffer($remoteOffer);
        }catch(\Throwable $exception){
            $this->peer->close();
            throw $exception;
        }
    }

    private static string $publicIp = "";
    private static int $udpPort = 0;
    private static bool $iceReported = false;
    private static ?\raspberry\utils\Logger $iceLogger = null;

    /**
     * Behind NAT (panels, containers) the client cannot reach the addresses the server finds on its own.
     * $udpPort makes every WebRTC connection use that one UDP port (it has to be open on the host) and
     * $publicIp is put into the SDP answer instead of the private address of the container.
     */
    public static function configureIce(string $publicIp, int $udpPort, ?\raspberry\utils\Logger $logger = null) : void{
        $publicIp = trim($publicIp);

        if($publicIp !== "" && filter_var($publicIp, FILTER_VALIDATE_IP) === false){
            $logger?->warning("nethernet-public-ip \"{$publicIp}\" is not a valid IP address, ignoring it");
            $publicIp = "";
        }

        self::$publicIp = $publicIp;
        self::$udpPort = $udpPort > 0 && $udpPort < 65536 ? $udpPort : 0;
        self::$iceLogger = $logger;
        self::$iceReported = false;
    }

    public static function getPublicIp() : string{
        return self::$publicIp;
    }

    /**
     * Calls the first method of $names that the extension has. Returns the options object to keep using, in case
     * the setter returns a new one instead of changing itself.
     *
     * @param list<string> $names
     * @param list<mixed>  $args
     */
    private static function callOption(PeerConnectionOptions $options, array $names, array $args, ?string &$used) : PeerConnectionOptions{
        foreach($names as $name){
            if(!method_exists($options, $name)){
                continue;
            }

            try{
                $result = $options->$name(...$args);
            }catch(Throwable $exception){
                self::$iceLogger?->warning("PeerConnectionOptions::{$name}() failed: " . $exception->getMessage());
                continue;
            }

            $used = $name;

            return $result instanceof PeerConnectionOptions ? $result : $options;
        }

        $used = null;

        return $options;
    }

    private static function applyIce(PeerConnectionOptions $options) : PeerConnectionOptions{
        if(self::$udpPort <= 0){
            return $options;
        }

        $port = self::$udpPort;

        $options = self::callOption($options, ["setEnableIceUdpMux", "setIceUdpMux", "setUdpMux", "enableIceUdpMux"], [true], $mux);

        $options = self::callOption($options, ["setPortRange", "setIcePortRange"], [$port, $port], $range);

        if($range === null){
            $options = self::callOption($options, ["setPortRangeBegin"], [$port], $begin);
            $options = self::callOption($options, ["setPortRangeEnd"], [$port], $end);
            $range = $begin !== null && $end !== null ? "{$begin}+{$end}" : null;
        }

        if(!self::$iceReported){
            self::$iceReported = true;

            if($mux === null || $range === null){
                self::$iceLogger?->warning("nethernet-udp-port is set but this webrtc extension has no matching option (udp mux: " . ($mux ?? "missing") . ", port range: " . ($range ?? "missing") . "). Available methods: " . implode(", ", get_class_methods(PeerConnectionOptions::class)));
            }else{
                self::$iceLogger?->info("WebRTC uses the fixed UDP port {$port} ({$mux}, {$range})");
            }
        }

        return $options;
    }

    /**
     * Puts the public IP into the private host candidates of an SDP answer.
     */
    public static function rewriteAnswer(string $answer) : string{
        if(self::$publicIp === ""){
            return $answer;
        }

        $public = self::$publicIp;

        return (string) preg_replace_callback(
            '/^(a=candidate:\S+\s+\d+\s+\S+\s+\d+\s+)(\S+)(\s+\d+\s+typ\s+host)/m',
            static function(array $match) use ($public) : string{
                $ip = $match[2];

                if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false){
                    return $match[0];
                }

                $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

                return $isPublic ? $match[0] : $match[1] . $public . $match[3];
            },
            $answer
        );
    }

    public function getId() : int{
        return $this->id;
    }

    public function isClosed() : bool{
        return $this->closed;
    }

    public function getState() : ConnectionState{
        return $this->peer->getState();
    }

    public function getRemoteAddress() : ?string{
        return $this->peer->getRemoteAddress();
    }

    public function addRemoteCandidate(
        string $candidate,
        ?string $mid = null
    ) : void{
        $this->assertOpen();

        if($candidate === "" || strlen($candidate) > 4096){
            throw new InvalidArgumentException("Invalid ICE candidate size");
        }

        if($mid !== null && strlen($mid) > 256){
            throw new InvalidArgumentException("ICE mid is too long");
        }

        $this->peer->addRemoteCandidate(
            IceCandidate::create($candidate, $mid)
        );
    }

    /**
     * Returns the local SDP answer once it becomes available.
     *
     * The signaling layer must deliver it to the remote peer.
     */
    public function takeLocalAnswer() : ?string{
        if($this->closed || $this->answerEmitted){
            return null;
        }

        if($this->peer->getGatheringState() !== GatheringState::COMPLETE){
            return null;
        }

        $answer = $this->peer->getLocalDescription();

        if($answer === null){
            return null;
        }

        if(!str_contains($answer, "a=candidate:")){
            throw new RuntimeException(
                "Completed SDP answer contains no ICE candidates"
            );
        }

        $this->answerEmitted = true;

        return $answer;
    }

    /**
     * @return list<array{candidate: string, mid: string}>
     */
    public function takeLocalCandidates() : array{
        if($this->closed){
            return [];
        }

        $result = [];

        foreach($this->peer->pollLocalCandidates() as $candidate){
            $result[] = [
                "candidate" => $candidate->getCandidate(),
                "mid" => $candidate->getMid()
            ];
        }

        return $result;
    }

    /**
     * Polls raw WebRTC messages.
     *
     * These still require NetherNet framing and Minecraft decoding.
     *
     * @return list<array{channel: string, payload: string}>
     */
    public function poll() : array{
        if($this->closed){
            return [];
        }

        $state = $this->peer->getState();

        if(
            $state === ConnectionState::FAILED ||
            $state === ConnectionState::CLOSED ||
            $state === ConnectionState::DISCONNECTED
        ){
            $this->close();
            return [];
        }

        if(
            $state !== ConnectionState::CONNECTED &&
            hrtime(true) - $this->createdAt > 30_000_000_000
        ){
            throw new RuntimeException("WebRTC connection timed out");
        }

        foreach($this->peer->pollDataChannels() as $channel){
            $label = $channel->getLabel();

            if(
                $label !== "ReliableDataChannel" &&
                $label !== "UnreliableDataChannel"
            ){
                $channel->close();
                throw new RuntimeException("Unexpected data channel");
            }

            if(isset($this->channels[$label])){
                $channel->close();
                throw new RuntimeException("Duplicate data channel");
            }

            if(
                $label === "ReliableDataChannel" &&
                (
                    $channel->isUnordered() ||
                    $channel->getMaxRetransmits() !== null ||
                    $channel->getMaxPacketLifeTime() !== null
                )
            ){
                $channel->close();
                throw new RuntimeException(
                    "Reliable channel has incompatible delivery settings"
                );
            }

            $this->channels[$label] = $channel;
        }

        $messages = [];

        foreach($this->channels as $label => $channel){
            if($channel->isClosed()){
                $this->close();
                return [];
            }

            if(!$channel->isOpen()){
                continue;
            }

            for(
                $count = 0;
                $count < self::MAX_MESSAGES_PER_POLL;
                ++$count
            ){
                $payload = $channel->receive();

                if($payload === null){
                    break;
                }

                if(strlen($payload) > self::MAX_MESSAGE_BYTES){
                    throw new RuntimeException("WebRTC message is too large");
                }

                $messages[] = [
                    "channel" => $label,
                    "payload" => $payload
                ];
            }
        }

        return $messages;
    }

    public function getReliableMessageSize() : int{
        $channel = $this->channels["ReliableDataChannel"] ?? null;

        if($channel === null || !$channel->isOpen()){
            throw new LogicException("Reliable data channel is not open");
        }

        return min(self::MAX_MESSAGE_BYTES, $channel->getMaxMessageSize());
    }

    public function getReliableBufferedAmount() : int{
        $channel = $this->channels["ReliableDataChannel"] ?? null;

        if($channel === null || !$channel->isOpen()){
            return PHP_INT_MAX;
        }

        return $channel->getBufferedAmount();
    }

    /**
     * Sends an already-framed nethernet message.
     *
     * Returns the native send() result unchanged.
     * Do not automatically resend based on this boolean.
     */
    public function sendRaw(
        string $payload,
        bool $reliable = true
    ) : bool{
        $this->assertOpen();

        $label = $reliable
            ? "ReliableDataChannel"
            : "UnreliableDataChannel";

        $channel = $this->channels[$label] ?? null;

        if($channel === null || !$channel->isOpen()){
            throw new LogicException("Data channel is not open");
        }

        $length = strlen($payload);

        if(
            $length > self::MAX_MESSAGE_BYTES ||
            $length > $channel->getMaxMessageSize()
        ){
            throw new InvalidArgumentException(
                "Message exceeds the channel's message size limit"
            );
        }

        if(
            $channel->getBufferedAmount() + $length >
            self::MAX_BUFFERED_BYTES
        ){
            throw new RuntimeException("WebRTC send buffer is full");
        }

        return $channel->send($payload);
    }

    public function close() : void{
        if($this->closed){
            return;
        }

        $this->closed = true;

        try{
            $this->peer->close();
        }finally{
            $this->channels = [];
        }
    }

    private function assertOpen() : void{
        if($this->closed){
            throw new LogicException("WebRTC session is closed");
        }
    }
}