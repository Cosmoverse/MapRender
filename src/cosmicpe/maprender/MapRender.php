<?php

declare(strict_types=1);

namespace cosmicpe\maprender;

use GdImage;
use Generator;
use InvalidArgumentException;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\Traverser;
use function count;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecolordeallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagesavealpha;
use function imagesetpixel;

final class MapRender{

	public static function create(Plugin $plugin) : self{
		return new self($plugin, MapColorPalette::default(), 16, 8, 2, Chunk::MIN_SUBCHUNK_INDEX, Chunk::MAX_SUBCHUNK_INDEX);
	}

	/**
	 * @param Plugin $plugin
	 * @param MapColorPalette $palette colors used to represent blocks
	 * @param int $chunks_per_tick number of chunks to process per tick
	 * @param int|null $chunk_loads_per_tick number of chunks to load per tick, or null to never read unloaded chunks
	 * @param int|null $chunk_gens_per_tick number of chunks to generate per tick, or null to never generate chunks
	 * @param int $min_subchunk_index sub-chunks at Y < this value are not scanned - useful for dimensions
	 * @param int $max_subchunk_index sub-chunks at Y > this value are not scanned - useful for dimensions
	 */
	public function __construct(
		readonly public Plugin $plugin,
		readonly public MapColorPalette $palette,
		readonly public int $chunks_per_tick,
		readonly public ?int $chunk_loads_per_tick,
		readonly public ?int $chunk_gens_per_tick,
		readonly public int $min_subchunk_index,
		readonly public int $max_subchunk_index
	){}

	/**
	 * Asynchronously reads chunks within the specified region from a world and renders a 2D map as a PHP GdImage with
	 * alpha channel information.
	 *
	 * @param World $world world to read chunks from
	 * @param int $x1 X coordinate of the chunk to read from (inclusive)
	 * @param int $z1 Z coordinate of the chunk to read from (inclusive)
	 * @param int $x2 X coordinate of the chunk to read until (inclusive)
	 * @param int $z2 Z coordinate of the chunk to read until (inclusive)
	 * @return Generator<mixed, Await::RESOLVE, void, GdImage>
	 */
	public function render(World $world, int $x1, int $z1, int $x2, int $z2) : Generator{
		$width = (1 + ($x2 - $x1)) << Chunk::COORD_BIT_SIZE;
		$height = (1 + ($z2 - $z1)) << Chunk::COORD_BIT_SIZE;
		$image = imagecreatetruecolor($width, $height);
		imagesavealpha($image, true);

		[$r, $g, $b, $a] = $this->palette->fallback;
		$background = imagecolorallocatealpha($image, $r, $g, $b, 127 - ($a >> 1));
		imagefill($image, 0, 0, $background);
		imagecolordeallocate($image, $background);
		imagealphablending($image, true);

		$elevation = [];
		$reader = new Traverser($this->read($world, $x1, $z1, $x2, $z2));
		while(yield from $reader->next($entry)){
			[$x, $y, $z, $color] = $entry;
			[$r, $g, $b, $a] = $this->palette->colors[$color];
			if($a === 255){
				$elevation[$x][$z] = $y;
				if(isset($elevation[$x][$z - 1], $elevation[$x - 1][$z - 1])){
					$north = $elevation[$x][$z - 1];
					$north_west = $elevation[$x - 1][$z - 1];
					$modifier = match(true){
						$north > $y && $north_west > $y => 0.5294,
						$north > $y && $north_west <= $y => 0.7058,
						$north >= $y || $north_west >= $y => 0.8627,
						default => null
					};
					if($modifier !== null){
						$r = (int) ($r * $modifier);
						$g = (int) ($g * $modifier);
						$b = (int) ($b * $modifier);
					}
				}
			}
			$color = imagecolorallocatealpha($image, $r, $g, $b, 127 - ($a >> 1));
			imagesetpixel($image, $x, $z, $color);
		}
		return $image;
	}

	/**
	 * Asynchronously reads chunks within the specified region from a world and returns a 2D color map.
	 *
	 * @param World $world world to read chunks from
	 * @param int $x1 X coordinate of the chunk to read from (inclusive)
	 * @param int $z1 Z coordinate of the chunk to read from (inclusive)
	 * @param int $x2 X coordinate of the chunk to read until (inclusive)
	 * @param int $z2 Z coordinate of the chunk to read until (inclusive)
	 * @return Generator<array{int, int, int, int}, Traverser::VALUE> an array of [X (0-W), Y (absolute), Z (0-H),
	 * colorIdx]. colors can be accessed by reading this->palette->colors[colorIdx]. W and H are the widths and heights
	 * of the map (or image) bounds.
	 */
	public function read(World $world, int $x1, int $z1, int $x2, int $z2) : Generator{
		$x1 <= $x2 || throw new InvalidArgumentException("x1 ({$x1}) must be <= x2 ({$x2})");
		$z1 <= $z2 || throw new InvalidArgumentException("z1 ({$z1}) must be <= z2 ({$z2})");

		$x0 = $x1;
		$z0 = $z1;
		$ax = 0;
		$az = 0;
		$chunk = null;
		$n_chunks = 0;
		$n_chunk_loads = 0;
		$n_chunk_gens = 0;
		$state = "read";
		while($state !== null){
			switch($state){
				case "next": // select coordinates for the next chunk
					$z0++;
					if($z0 <= $z2){
						$az += Chunk::EDGE_LENGTH;
						$state = "read";
						break;
					}
					$z0 = $z1;
					$az = 0;
					$x0++;
					if($x0 <= $x2){
						$ax += Chunk::EDGE_LENGTH;
						$state = "read";
						break;
					}
					$state = null;
					break;
				case "read": // read current chunk
					if(!$world->isLoaded()){
						$state = null;
						break;
					}
					$chunk = $world->getChunk($x0, $z0);
					if($chunk === null && $this->chunk_loads_per_tick !== null){
						$chunk = $world->loadChunk($x0, $z0);
						if($chunk !== null){
							$n_chunk_loads++;
						}
					}
					if($chunk === null && $this->chunk_gens_per_tick !== null){
						$chunk = yield from Await::promise(fn($resolve) => $world->orderChunkPopulation($x0, $z0, null)->onCompletion($resolve, fn() => $resolve(null)));
						if($chunk !== null){
							$n_chunk_gens++;
						}
					}
					if($chunk === null){
						$state = "next";
					}else{
						$n_chunks++;
						$state = "color";
					}
					break;
				case "color": // compute colors for current chunk
					foreach($this->colors($chunk) as [$dx, $y, $dz, $color]){
						yield [$ax + $dx, $y, $az + $dz, $color] => Traverser::VALUE;
					}
					$state = "sleep";
					break;
				case "sleep": // evaluate sleeping condition (sleep if necessary)
					$sleep = ($n_chunks > 0 && ($n_chunks % $this->chunks_per_tick) === 0) ||
						($this->chunk_loads_per_tick !== null && $n_chunk_loads > 0 && ($n_chunk_loads % $this->chunk_loads_per_tick) === 0) ||
						($this->chunk_gens_per_tick !== null && $n_chunk_gens > 0 && ($n_chunk_gens % $this->chunk_gens_per_tick) === 0);
					if($sleep){
						$n_chunks = 0;
						$n_chunk_loads = 0;
						$n_chunk_gens = 0;
						yield from $this->sleep();
					}
					$state = "next";
					break;
			}
		}
	}

	/**
	 * Reads blocks at every column (XZ) of the chunk. A column scan terminates when a block with a color opacity of 255
	 * is encountered. Blocks are returned from BOTTOM to TOP.
	 *
	 * @param Chunk $chunk the chunk to read colors from
	 * @return Generator<array{int, int, int, int}> an array of [X (0-15), Y (absolute), Z (0-15), colorIdx]. colors can
	 * be accessed by reading this->palette->colors[colorIdx].
	 */
	public function colors(Chunk $chunk) : Generator{
		$sub_chunks = $chunk->getSubChunks();
		for($x = 0; $x < Chunk::EDGE_LENGTH; $x++){
			for($z = 0; $z < Chunk::EDGE_LENGTH; $z++){
				$found = [];
				for($y = $this->max_subchunk_index; $y >= $this->min_subchunk_index; $y--){
					$sub_chunk = $sub_chunks[$y];
					if($sub_chunk->isEmptyFast()){
						continue;
					}
					$yo = $y << SubChunk::COORD_BIT_SIZE;
					for($dy = SubChunk::EDGE_LENGTH - 1; $dy >= 0; $dy--){
						$block_id = $sub_chunk->getBlockStateId($x, $dy, $z);
						if(!isset($this->palette->blocks[$block_id])){
							continue;
						}
						$color = $this->palette->blocks[$block_id];
						$found[] = [$x, $yo + $dy, $z, $color];
						$a = $this->palette->colors[$color][3];
						if($a === 255){
							break 2;
						}
						if(count($found) === 15){
							// bail if we could not find a solid block after encountering 15 translucent blocks
							break 2;
						}
					}
				}
				for($i = count($found) - 1; $i >= 0; $i--){
					yield $found[$i];
				}
			}
		}
	}

	/**
	 * Sleeps until the next tick.
	 *
	 * @return Generator<mixed, Await::RESOLVE, void, null>
	 */
	private function sleep() : Generator{
		return yield from Await::promise(fn($resolve) => $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask($resolve), 1));
	}
}