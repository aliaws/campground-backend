<?php

use App\Support\PublicStorageUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Stores each map photo's real pixel size so the canvas can take the
 * photo's own shape instead of a fixed 4:3 box with `background-size:
 * cover`, which cropped every photo that wasn't exactly 4:3.
 *
 * Marker x/y/width/height are percentages of the canvas. Until now the
 * canvas showed only the 4:3 middle of a photo, so existing markers are
 * converted to percentages of the whole photo here, keeping every marker
 * on the same spot of the picture. A map gets a size only when its file is
 * readable; a map without one keeps the old 4:3 rendering on the frontend.
 */
return new class extends Migration
{
    private const LEGACY_ASPECT = 4 / 3;

    public function up(): void
    {
        Schema::table('site_maps', function (Blueprint $table) {
            $table->unsignedInteger('image_width')->nullable()->after('image_url');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
        });

        foreach (DB::table('site_maps')->whereNotNull('image_url')->get() as $map) {
            [$width, $height] = $this->imageSize($map->image_url);
            if (! $width || ! $height) {
                continue;
            }

            DB::transaction(function () use ($map, $width, $height) {
                $this->convertElements($map->id, $width / $height, true);
                DB::table('site_maps')->where('id', $map->id)->update([
                    'image_width' => $width,
                    'image_height' => $height,
                ]);
            });
        }
    }

    public function down(): void
    {
        foreach (DB::table('site_maps')->whereNotNull('image_width')->whereNotNull('image_height')->get() as $map) {
            DB::transaction(fn () => $this->convertElements($map->id, $map->image_width / $map->image_height, false));
        }

        Schema::table('site_maps', function (Blueprint $table) {
            $table->dropColumn(['image_width', 'image_height']);
        });
    }

    /** @return array{0: ?int, 1: ?int} */
    private function imageSize(string $imageUrl): array
    {
        if (! PublicStorageUrl::isOwnStoragePath($imageUrl)) {
            return [null, null];
        }

        $path = PublicStorageUrl::diskRelativePath($imageUrl);
        if (! Storage::disk('public')->exists($path)) {
            return [null, null];
        }

        $size = @getimagesize(Storage::disk('public')->path($path));

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    /**
     * $toImage: 4:3-crop percentages -> whole-photo percentages, or back.
     * Only the cropped axis changes; the other axis was already the whole
     * photo in the old rendering.
     */
    private function convertElements(string $mapId, float $aspect, bool $toImage): void
    {
        if (abs($aspect - self::LEGACY_ASPECT) < 0.0001) {
            return;
        }

        $wider = $aspect > self::LEGACY_ASPECT;
        $fraction = $wider ? self::LEGACY_ASPECT / $aspect : $aspect / self::LEGACY_ASPECT;
        $factor = $toImage ? $fraction : 1 / $fraction;
        $pos = $wider ? 'x' : 'y';
        $size = $wider ? 'width' : 'height';

        foreach (DB::table('site_map_elements')->where('site_map_id', $mapId)->get() as $el) {
            DB::table('site_map_elements')->where('id', $el->id)->update([
                $pos => 50 + ((float) $el->{$pos} - 50) * $factor,
                $size => (float) $el->{$size} * $factor,
            ]);
        }
    }
};
