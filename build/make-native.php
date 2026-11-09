<?php

declare(strict_types=1);

namespace raspberry\build;

use function array_slice;
use function copy;
use function dirname;
use function escapeshellarg;
use function file_exists;
use function fwrite;
use function function_exists;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function mkdir;
use function passthru;
use function php_uname;
use function shell_exec;
use function strtolower;
use function trim;

use const PHP_OS_FAMILY;
use const STDERR;

if(PHP_SAPI !== "cli"){
    exit("Run this script from the command line.\n");
}

$root = dirname(__DIR__);
$os = PHP_OS_FAMILY;
$arch = strtolower(php_uname("m"));
$name = match($os){
    "Windows" => "raspberry_native.dll",
    "Darwin" => "libraspberry_native.dylib",
    default => "libraspberry_native.so"
};

$manifest = $root . "/native/Cargo.toml";
$target = null;
$extra = [];
foreach(array_slice($argv ?? [], 1) as $arg){
    if($arg === "--help" || $arg === "-h"){
        echo "Usage: php build/make-native.php [--target <rust-target>] [-- <extra cargo args>]\n";
        echo "Builds the Rust native library (cdylib) for Linux, macOS, or Windows and copies it to bin/native/.\n";
        echo "Requires Rust/Cargo: https://rustup.rs\n";
        exit(0);
    }
    if(str_starts_with($arg, "--target=")){
        $target = substr($arg, 9);
        continue;
    }
    if($arg === "--target"){
        $target = "__NEXT__";
        continue;
    }
    if($target === "__NEXT__"){
        $target = $arg;
        continue;
    }
    $extra[] = $arg;
}

$cargo = trim((string) (shell_exec("command -v cargo 2>/dev/null") ?? ""));
if($cargo === "" && $os === "Windows"){
    $cargo = trim((string) (shell_exec("where cargo 2>nul") ?? ""));
}
if($cargo === "" && !file_exists("/usr/bin/cargo") && !file_exists("/usr/local/bin/cargo")){
    fwrite(STDERR, "Cargo/Rust not found. Install Rust from https://rustup.rs (Linux/macOS: curl https://sh.rustup.rs -sSf | sh).\n");
    exit(1);
}

$cmd = "cargo build --release --manifest-path " . escapeshellarg($manifest);
if(is_string($target) && $target !== "" && $target !== "__NEXT__"){
    $cmd .= " --target " . escapeshellarg($target);
}
foreach($extra as $arg){
    $cmd .= " " . escapeshellarg($arg);
}

fwrite(STDERR, "Building native library for {$os} ({$arch})...\n");
passthru($cmd, $status);

if($status !== 0){
    fwrite(STDERR, "cargo build failed (exit {$status}). Ensure Rust is installed and the target is available (rustup target add <target>).\n");
    exit(1);
}

$built = $root . "/native/target/" . (is_string($target) && $target !== "" && $target !== "__NEXT__" ? $target . "/" : "") . "release/" . $name;
$folder = $root . "/bin/native";

if(!is_file($built)){
    // macOS may produce .dylib with different naming in some setups; try common fallbacks
    $fallbacks = [
        $root . "/native/target/release/" . $name,
        $root . "/native/target/release/libraspberry_native.so",
        $root . "/native/target/release/libraspberry_native.dylib",
        $root . "/native/target/release/raspberry_native.dll",
    ];
    foreach($fallbacks as $fb){
        if(is_file($fb)){ $built = $fb; $name = basename($fb); break; }
    }
}

if(!is_file($built)){
    fwrite(STDERR, "cargo finished but the library was not found at {$built}\n");
    exit(1);
}

if(!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)){
    fwrite(STDERR, "Could not create {$folder}\n");
    exit(1);
}

if(!copy($built, $folder . "/" . $name)){
    fwrite(STDERR, "Could not copy the library to {$folder} (is the server still using it?)\n");
    exit(1);
}

echo "Built bin/native/{$name} ({$os})\n";