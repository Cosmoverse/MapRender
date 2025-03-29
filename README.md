# MapRender
A library for rendering a topographic view of sections within a world in PocketMine-MP.

![A render of a few world regions from a PocketMine-MP server](https://github.com/user-attachments/assets/51fe57e6-07ae-4e8a-82d2-2f30d3507d8a)

## Features
- Vibrant color palette spanning 800+ blocks
- Represent translucent blocks
- Elevation detail using shadows
- Render asynchronously

## Usage
MapRender uses [SOF3/await-generator](https://github.com/SOF3/await-generator) to perform rendering asynchronously. A
MapRender instance may be initialized once and reused. MapRender is stateless, meaning you may invoke `MapRender::render()`
concurrently. `render()` returns a GdImage which gives you the ability to export the image as needed, or perhaps further
preprocess the image.  See [examples](#Examples) for a few ways you can use this. Coordinate parameters `$x1`, `$x2`,
`$z1`, and `$z2` are chunk coordinates (not to be confused with block coordinates).
```php
use cosmicpe\maprender\MapRender;

$render = MapRender::create($plugin);
$image = yield from $render->render($world, $x1, $z1, $x2, $z2);
$output = $plugin->getDataFolder() . DIRECTORY_SEPARATOR . "map-render.png";
imagepng($image, $output);
```

If you are unfamiliar with await-generator and are looking for a quick setup, use `Await::f2c()` to make a callback wrapper.
```php
/** @param Closure(GdImage) : void */
public function render(World $world, int $x1, int $z1, int $x2, int $z2, Closure $callback) : void{
	$render = MapRender::create($plugin);
	$task = $render->render($world, $x1, $z1, $x2, $z2);
	Await::f2c(function() use($task, $callback) : Generator{
		$image = yield from $task;
		$callback($image);
	});
}
```

## Examples
If you are working with block coordinates, use `Chunk::COORD_BIT_SIZE` to infer chunk coordinates. The example below
renders a 176x176 image (`((radius x 2) + 1) * 16`) where the center chunk is the spawn chunk of the default world.
```php
$radius = 5;
$world = $server->getWorldManager()->getDefaultWorld();
$spawn = $world->getSpawnLocation();
$x = $spawn->getFloorX() >> Chunk::COORD_BIT_SIZE;
$z = $spawn->getFloorZ() >> Chunk::COORD_BIT_SIZE;

$render = MapRender::create($plugin);
$image = yield from $render->render($world, $x - $radius, $z - $radius, $x + $radius, $z + $radius);
$output = $plugin->getDataFolder() . DIRECTORY_SEPARATOR . "map-render.png";
imagepng($image, $output);
```

If you are looking to transport data and hence need to export map image as raw PNG bytes, pass an in-memory stream
instead of a file path.
```php
$resource = fopen("php://memory", "rb+");
try{
	imagepng($image, $resource);
	fseek($resource, 0);
	$bytes = stream_get_contents($resource);
}finally{
	fclose($resource);
}

$output = $plugin->getDataFolder() . DIRECTORY_SEPARATOR . "map-render.png";
file_put_contents($output, $bytes);
```

By default, every block is represented as a 1x1 pixel. This means a map spanning 5x5 chunks has a resolution of 80x80.
You can use `imagescale()` to rescale the image. The example below scales the image by 4x (meaning each block is
represented using 4x4 pixels).
```php
$width = imagesx($image) * 4;
$image = imagescale($image, $width, -1, IMG_NEAREST_NEIGHBOUR);
imagesavealpha($image, true);
imagepng($image, $output);
```

MapRender has default performance settings to ensure server load is kept minimum and at the same time maps render as
quickly as possible. If these settings do not suit your server hardware, tweak them by creating a custom MapRender.
```php
$parent = MapRender::create($plugin);

// set custom chunk_loads_per_tick, chunk_gens_per_tick
$chunk_loads_per_tick = 4;
$chunk_gens_per_tick = 1;
$render = new MapRender(
	$parent->plugin,
	$parent->palette,
	$parent->chunks_per_tick,
	$chunk_loads_per_tick,
	$chunk_gens_per_tick,
	$parent->min_subchunk_index,
	$parent->max_subchunk_index
);
```