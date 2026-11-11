<?php

declare(strict_types=1);

namespace raspberry\native;

use raspberry\Server;

use Closure;
use FFI;
use FFI\CData;
use Throwable;

use function count;
use function extension_loaded;
use function is_file;

use const PHP_OS_FAMILY;

class Native{

    /** the library has to speak this version of the functions below */
    public const ABI_VERSION = 4;

    private const HEADER = <<<'C'
        uint32_t rx_version(void);
        void rx_free(char* data, size_t length);
        int32_t rx_poll(uint64_t* id, char** out, size_t* out_length);

        uint64_t rx_chunk_new(uint32_t air, uint32_t biome);
        void rx_chunk_free(uint64_t chunk);
        uint32_t rx_chunk_get_block(uint64_t chunk, uint32_t x, int32_t y, uint32_t z, uint32_t layer);
        int32_t rx_chunk_set_block(uint64_t chunk, uint32_t x, int32_t y, uint32_t z, uint32_t layer, uint32_t state);
        uint32_t rx_chunk_get_biome(uint64_t chunk, uint32_t x, int32_t y, uint32_t z);
        int32_t rx_chunk_set_biome(uint64_t chunk, uint32_t x, int32_t y, uint32_t z, uint32_t biome);
        void rx_chunk_fill(uint64_t chunk, int32_t min_y, int32_t max_y, uint32_t layer, uint32_t state);
        void rx_chunk_fill_biome(uint64_t chunk, uint32_t biome);
        int32_t rx_chunk_highest_subchunk(uint64_t chunk);
        void rx_chunk_collect_garbage(uint64_t chunk);
        int32_t rx_chunk_export(uint64_t chunk, char** out, size_t* out_length);
        int32_t rx_chunk_import(uint64_t chunk, const char* data, size_t length);
        uint64_t rx_chunk_encode(uint64_t chunk, int32_t chunk_x, int32_t chunk_z, uint64_t revision, const char* extra, size_t extra_length, int32_t threshold);
        int32_t rx_chunk_copy(uint64_t from, uint64_t to);

        void rx_generator_states(char** out, size_t* out_length);
        uint64_t rx_old_generate(int64_t seed, const uint32_t* states, size_t count);
        void rx_old_border(uint64_t chunk, int32_t chunk_x, int32_t chunk_z, uint32_t barrier);
        uint64_t rx_pre_normal_new(int64_t seed, const uint32_t* states, size_t count);
        void rx_pre_normal_free(uint64_t generator);
        uint64_t rx_pre_normal_chunk(uint64_t generator, int32_t chunk_x, int32_t chunk_z);
        uint64_t rx_pre_normal_spawn(uint64_t generator);

        uint64_t rx_script_new(const char* pack_name, const char* shim_js);
        int32_t rx_script_eval(uint64_t handle, const char* code);
        int32_t rx_script_poll_log(uint64_t handle, char** out, size_t* out_len);
        void rx_script_free(uint64_t handle);

        uint64_t rx_bridge_new(const char* pack_name, const char* shim_js);
        int32_t rx_bridge_eval(uint64_t handle, const char* code);
        int32_t rx_bridge_poll_request(uint64_t handle, char** out_method, size_t* out_method_len, char** out_params, size_t* out_params_len);
        int32_t rx_bridge_submit_response(uint64_t handle, uint64_t request_id, const char* result_json, const char* error_json);
        int32_t rx_bridge_poll_log(uint64_t handle, char** out, size_t* out_len);
        void rx_bridge_free(uint64_t handle);
        C;

    private static ?FFI $ffi = null;

    private static ?string $error = "the native library was not loaded";

    /** @var array<int, Closure(string) : void> work ID => what to do with its result */
    private static array $waiting = [];

    private function __construct(){
    }

    /**
     * The file name of the library on this system.
     */
    public static function getLibraryName() : string{
        return match(PHP_OS_FAMILY){
            "Windows" => "raspberry_native.dll",
            "Darwin" => "libraspberry_native.dylib",
            default => "libraspberry_native.so"
        };
    }

    /**
     * Loads the library at $path. False when it cannot be, getError() says why.
     */
    public static function load(string $path) : bool{
        if(self::$ffi !== null){
            return true;
        }

        if(!extension_loaded("ffi")){
            self::$error = "this PHP was built without FFI";
            return false;
        }

        if(!is_file($path)){
            self::$error = "{$path} does not exist, build it with composer make-native";
            return false;
        }

        try{
            $ffi = FFI::cdef(self::HEADER, $path);
            $version = $ffi->rx_version();
        }catch(Throwable $exception){
            self::$error = $exception->getMessage();
            return false;
        }

        if($version !== self::ABI_VERSION){
            self::$error = "{$path} is version {$version} but this TodiumX needs version " . self::ABI_VERSION . ", build it again";
            return false;
        }

        self::$ffi = $ffi;
        self::$error = null;

        return true;
    }

    public static function isLoaded() : bool{
        return self::$ffi !== null;
    }

    /**
     * Whether the library is loaded and speaks the right ABI, so the embedded script engine can be used.
     */
    public static function isAvailable() : bool{
        return self::$ffi !== null;
    }

    public static function getError() : ?string{
        return self::$error;
    }

    /**
     * @internal
     */
    public static function get() : FFI{
        return self::$ffi ?? throw new NativeException("The native library is not available: " . self::$error);
    }

    /**
     * How much started work has not come back yet.
     */
    public static function getPendingCount() : int{
        return count(self::$waiting);
    }

    /**
     * @internal
     *
     * @param Closure(string) : void $onDone
     */
    public static function await(int $id, Closure $onDone) : void{
        self::$waiting[$id] = $onDone;
    }

    /**
     * Hands the results of finished work to whoever waits for them. The server does this every
     * tick and in between. A callback that throws is logged, the server keeps going.
     */
    public static function poll() : int{
        if(self::$ffi === null || self::$waiting === []){
            return 0;
        }

        $ffi = self::$ffi;
        $id = $ffi->new("uint64_t");
        $out = $ffi->new("char*");
        $length = $ffi->new("size_t");
        $count = 0;

        while($ffi->rx_poll(FFI::addr($id), FFI::addr($out), FFI::addr($length)) === 1){
            $data = self::take($out, $length->cdata);
            $onDone = self::$waiting[$id->cdata] ?? null;
            unset(self::$waiting[$id->cdata]);
            ++$count;

            if($onDone === null){
                continue;
            }

            try{
                $onDone($data);
            }catch(Throwable $exception){
                Server::getInstance()->getLogger()->error("A native callback threw " . $exception::class . ": " . $exception->getMessage() . " at " . $exception->getFile() . ":" . $exception->getLine());
            }
        }

        return $count;
    }

    /**
     * @internal
     */
    public static function take(CData $buffer, int $length) : string{
        $data = $length === 0 ? "" : FFI::string($buffer, $length);
        self::get()->rx_free($buffer, $length);

        return $data;
    }

    public static function scriptNew(string $packName, string $shimJs) : int{
        if(self::$ffi === null) return 0;
        try{
            return (int) self::$ffi->rx_script_new($packName, $shimJs);
        }catch(Throwable){
            return 0;
        }
    }

    public static function scriptEval(int $handle, string $code) : bool{
        if(self::$ffi === null || $handle <= 0) return false;
        try{
            return self::$ffi->rx_script_eval($handle, $code) === 1;
        }catch(Throwable){
            return false;
        }
    }

    public static function scriptPollLog(int $handle) : ?string{
        if(self::$ffi === null || $handle <= 0) return null;
        try{
            $ffi = self::$ffi;
            $out = $ffi->new("char*");
            $len = $ffi->new("size_t");
            $has = $ffi->rx_script_poll_log($handle, FFI::addr($out), FFI::addr($len));
            if($has !== 1) return null;
            return self::take($out, (int)$len->cdata);
        }catch(Throwable){
            return null;
        }
    }

    public static function scriptFree(int $handle) : void{
        if(self::$ffi === null || $handle <= 0) return;
        try{
            self::$ffi->rx_script_free($handle);
        }catch(Throwable){
            // ignore
        }
    }

    public static function bridgeNew(string $packName, string $shimJs) : int{
        if(self::$ffi === null) return 0;
        try{
            return (int) self::$ffi->rx_bridge_new($packName, $shimJs);
        }catch(Throwable){
            return 0;
        }
    }

    public static function bridgeEval(int $handle, string $code) : bool{
        if(self::$ffi === null || $handle <= 0) return false;
        try{
            return self::$ffi->rx_bridge_eval($handle, $code) === 1;
        }catch(Throwable){
            return false;
        }
    }

    public static function bridgePollRequest(int $handle) : ?array{
        if(self::$ffi === null || $handle <= 0) return null;
        try{
            $ffi = self::$ffi;
            $m = $ffi->new("char*");
            $ml = $ffi->new("size_t");
            $p = $ffi->new("char*");
            $pl = $ffi->new("size_t");
            $has = $ffi->rx_bridge_poll_request($handle, FFI::addr($m), FFI::addr($ml), FFI::addr($p), FFI::addr($pl));
            if($has !== 1) return null;
            $method = self::take($m, (int)$ml->cdata);
            $params = self::take($p, (int)$pl->cdata);
            return ["method" => $method, "params" => json_decode($params, true)];
        }catch(Throwable){
            return null;
        }
    }

    public static function bridgeSubmitResponse(int $handle, int $requestId, array $result, ?string $error = null) : bool{
        if(self::$ffi === null || $handle <= 0) return false;
        try{
            $r = json_encode($result);
            $e = $error ?? "";
            return self::$ffi->rx_bridge_submit_response($handle, $requestId, $r, $e) === 1;
        }catch(Throwable){
            return false;
        }
    }

    public static function bridgeFree(int $handle) : void{
        if(self::$ffi === null || $handle <= 0) return;
        try{
            self::$ffi->rx_bridge_free($handle);
        }catch(Throwable){
            // ignore
        }
    }
}