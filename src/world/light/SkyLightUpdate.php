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

namespace pocketmine\world\light;

use pocketmine\block\BlockFactory;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\LightArray;
use pocketmine\world\World;
use function max;

class SkyLightUpdate extends LightUpdate{
	protected function updateLightArrayRef() : void{
		$this->currentLightArray = $this->subChunkHandler->currentSubChunk->getBlockSkyLightArray();
	}

	protected function getEffectiveLight(int $x, int $y, int $z) : int{
		if($y >= World::Y_MAX){
			$this->subChunkHandler->invalidate();
			return 15;
		}
		return parent::getEffectiveLight($x, $y, $z);
	}

	public function recalculateNode(int $x, int $y, int $z) : void{
		$chunk = $this->world->getChunk($x >> 4, $z >> 4);
		if($chunk === null){
			return;
		}
		$oldHeightMap = $chunk->getHeightMap($x & 0xf, $z & 0xf);
		$source = $this->world->getBlockAt($x, $y, $z);

		$yPlusOne = $y + 1;

		if($yPlusOne === $oldHeightMap){ //Block changed directly beneath the heightmap. Check if a block was removed or changed to a different light-filter.
			$newHeightMap = self::calculateHeightMap($x & 0x0f, $z & 0x0f, $chunk);
		}elseif($yPlusOne > $oldHeightMap){ //Block changed above the heightmap.
			if($source->getLightFilter() > 0 or $source->diffusesSkyLight()){
				$newHeightMap = $yPlusOne;
			}else{ //Block changed which has no effect on direct sky light, for example placing or removing glass.
				return;
			}
		}else{ //Block changed below heightmap
			$newHeightMap = $oldHeightMap;
		}
		$chunk->setHeightMap($x & 0xf, $z & 0xf, $newHeightMap);

		if($newHeightMap > $oldHeightMap){ //Heightmap increase, block placed, remove sky light
			for($i = $y; $i >= $oldHeightMap; --$i){
				$this->setAndUpdateLight($x, $i, $z, 0); //Remove all light beneath, adjacent recalculation will handle the rest.
			}
		}elseif($newHeightMap < $oldHeightMap){ //Heightmap decrease, block changed or removed, add sky light
			for($i = $y; $i >= $newHeightMap; --$i){
				$this->setAndUpdateLight($x, $i, $z, 15);
			}
		}else{ //No heightmap change, block changed "underground"
			$this->setAndUpdateLight($x, $y, $z, max(0, $this->getHighestAdjacentLight($x, $y, $z) - BlockFactory::$lightFilter[$source->getFullId()]));
		}
	}

	public function recalculateChunk(int $chunkX, int $chunkZ) : void{
		$chunk = $this->world->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			throw new \InvalidArgumentException("Cannot recalculate light for nonexisting chunk");
		}

		$highestOccupiedSubChunk = 0;
		for($chunkY = $chunk->getSubChunks()->count() - 1; $chunkY >= 0; --$chunkY){
			if(($subChunk = $chunk->getSubChunk($chunkY))->isEmptyFast()){
				$subChunk->setBlockSkyLightArray(LightArray::fill(15));
			}else{
				$highestOccupiedSubChunk = $chunkY;
				break;
			}
		}
		for($chunkY = $highestOccupiedSubChunk; $chunkY >= 0; --$chunkY){
			$chunk->getSubChunk($chunkY)->setBlockSkyLightArray(LightArray::fill(0));
		}

		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$heightMap = self::calculateHeightMap($x, $z, $chunk);
				$chunk->setHeightMap($x, $z, $heightMap);

				for($y = ($highestOccupiedSubChunk * 16) + 15; $y >= $heightMap; --$y){
					$this->setAndUpdateLight(
						($chunkX << 4) | $x,
						$y,
						($chunkZ << 4) | $z,
						15
					);
				}

				if($heightMap > 0){
					$this->setAndUpdateLight(
						($chunkX << 4) | $x,
						$heightMap - 1,
						($chunkZ << 4) | $z,
						15 - BlockFactory::$lightFilter[$chunk->getFullBlock($x, $heightMap - 1, $z)]
					);
				}
			}
		}
	}

	/**
	 * Calculates the appropriate heightmap value for the given coordinates in the target chunk.
	 *
	 * @param int   $x 0-15
	 * @param int   $z 0-15
	 * @param Chunk $chunk
	 *
	 * @return int New calculated heightmap value (0-256 inclusive)
	 */
	private static function calculateHeightMap(int $x, int $z, Chunk $chunk) : int{
		$max = $chunk->getHighestBlockAt($x, $z);
		for($y = $max; $y >= 0; --$y){
			if(BlockFactory::$lightFilter[$state = $chunk->getFullBlock($x, $y, $z)] > 1 or BlockFactory::$diffusesSkyLight[$state]){
				break;
			}
		}

		return $y + 1;
	}
}
