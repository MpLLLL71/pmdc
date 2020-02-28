<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\tools\compact_regions;

use pocketmine\world\format\io\region\RegionLoader;
use function clearstatcache;
use function count;
use function defined;
use function dirname;
use function file_exists;
use function filesize;
use function in_array;
use function is_dir;
use function is_file;
use function number_format;
use function pathinfo;
use function rename;
use function round;
use function scandir;
use function unlink;
use function zlib_decode;
use function zlib_encode;

require dirname(__DIR__) . '/vendor/autoload.php';

const SUPPORTED_EXTENSIONS = [
	"mcr",
	"mca",
	"mcapm"
];

/**
 * @param string[] $files
 */
function find_regions_recursive(string $dir, array &$files) : void{
	foreach(scandir($dir, SCANDIR_SORT_NONE) as $file){
		if($file === "." or $file === ".."){
			continue;
		}
		$fullPath = $dir . "/" . $file;
		if(
			in_array(pathinfo($fullPath, PATHINFO_EXTENSION), SUPPORTED_EXTENSIONS, true) and
			is_file($fullPath)
		){
			$files[] = $fullPath;
		}elseif(is_dir($fullPath)){
			find_regions_recursive($fullPath, $files);
		}
	}
}

/**
 * @param string[] $argv
 */
function main(array $argv) : int{
	if(!isset($argv[1])){
		echo "Usage: " . PHP_BINARY . " " . __FILE__ . " <path to region folder or file>\n";
		return 1;
	}

	$logger = \GlobalLogger::get();

	$files = [];
	if(is_file($argv[1])){
		$files[] = $argv[1];
	}elseif(is_dir($argv[1])){
		find_regions_recursive($argv[1], $files);
	}
	if(count($files) === 0){
		echo "No supported files found\n";
		return 1;
	}
	$currentSize = 0;
	foreach($files as $file){
		$currentSize += filesize($file);
	}
	$logger->info("Discovered " . count($files) . " files totalling " . number_format($currentSize) . " bytes");

	foreach($files as $file){
		$newFile = $file . ".compacted";
		$oldRegion = new RegionLoader($file);
		$oldRegion->open();
		$newRegion = new RegionLoader($newFile);
		$newRegion->open();

		$emptyRegion = true;
		for($x = 0; $x < 32; $x++){
			for($z = 0; $z < 32; $z++){
				$data = $oldRegion->readChunk($x, $z);
				if($data !== null){
					$emptyRegion = false;
					$newRegion->writeChunk($x, $z, $data);
				}
			}
		}

		$oldRegion->close();
		$newRegion->close();
		unlink($file);
		if(!$emptyRegion){
			rename($newFile, $file);
		}else{
			unlink($newFile);
		}
		$logger->info("Compacted region $file");
	}

	clearstatcache();
	$newSize = 0;
	foreach($files as $file){
		$newSize += file_exists($file) ? filesize($file) : 0;
	}
	$diff = $currentSize - $newSize;
	$logger->info("Finished compaction of " . count($files) . " files. Freed " . number_format($diff) . " bytes of space (" . round(($diff / $currentSize) * 100, 2) . "% reduction).");
	return 0;
}

if(!defined('pocketmine\_PHPSTAN_ANALYSIS')){
	exit(main($argv));
}
