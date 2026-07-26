<?php

declare(strict_types=1);

namespace App\Core\Helpers\UploadMedia;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class UploadMediaHelper
{
    private const MAX_FILE_SIZE = 10485760; // 10MB

    public static array $imageMimeTypes = [
        'image/apng',
        'image/avif',
        'image/gif',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/svg+xml',
        'image/svg',
        'image/webp',
    ];

    public static array $videoMimeTypes = [
        'application/annodex',
        'application/mp4',
        'application/ogg',
        'application/vnd.rn-realmedia',
        'application/x-matroska',
        'video/3gpp',
        'video/3gpp2',
        'video/annodex',
        'video/divx',
        'video/flv',
        'video/h264',
        'video/mp4',
        'video/mp4v-es',
        'video/mpeg',
        'video/mpeg-2',
        'video/mpeg4',
        'video/ogg',
        'video/ogm',
        'video/quicktime',
        'video/ty',
        'video/vdo',
        'video/vivo',
        'video/vnd.rn-realvideo',
        'video/vnd.vivo',
        'video/webm',
        'video/x-bin',
        'video/x-cdg',
        'video/x-divx',
        'video/x-dv',
        'video/x-flv',
        'video/x-la-asf',
        'video/x-m4v',
        'video/x-matroska',
        'video/x-motion-jpeg',
        'video/x-ms-asf',
        'video/x-ms-dvr',
        'video/x-ms-wm',
        'video/x-ms-wmv',
        'video/x-msvideo',
        'video/x-sgi-movie',
        'video/x-tivo',
        'video/avi',
        'video/x-ms-asx',
        'video/x-ms-wvx',
        'video/x-ms-wmx',
    ];

    public static array $allowedImageExtensions = [
        '.png',
        '.jpg',
        '.jpeg',
        '.svg',
        '.webp',
        '.gif',
    ];

    public static array $pdfMimeTypes = [
        'application/pdf',
    ];

    /**
     * Get validation rules for image uploads
     *
     * @param  bool  $required  Whether the image is required
     * @param  int  $maxSize  Maximum file size in bytes
     */
    public static function imageRules(bool $required = true, int $maxSize = self::MAX_FILE_SIZE): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'mimetypes:'.implode(',', self::$imageMimeTypes),
            'max:'.($maxSize / 1024),
        ]);
    }

    /**
     * Get validation rules for PDF uploads
     *
     * @param  bool  $required  Whether the PDF is required
     * @param  int  $maxSize  Maximum file size in bytes
     */
    public static function pdfRules(bool $required = true, int $maxSize = self::MAX_FILE_SIZE): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'mimetypes:'.implode(',', self::$pdfMimeTypes),
            'max:'.($maxSize / 1024),
        ]);
    }

    /**
     * Get validation rules for video uploads
     *
     * @param  bool  $required  Whether the video is required
     * @param  int  $maxSize  Maximum file size in bytes
     */
    public static function videoRules(bool $required = true, int $maxSize = self::MAX_FILE_SIZE): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'mimetypes:'.implode(',', self::$videoMimeTypes),
            'max:'.($maxSize / 1024),
        ]);
    }

    /**
     * Generate an HTML img tag with specified attributes
     *
     * @param  string  $url  Image URL
     * @param  int  $width  Width in pixels
     * @param  int  $height  Height in pixels
     * @param  string|null  $alt  Alt text
     * @param  array  $attributes  Additional HTML attributes
     */
    public static function image(
        string $url,
        int $width = 100,
        int $height = 100,
        ?string $alt = null,
        array $attributes = []
    ): string {
        $defaultAttributes = [
            'src' => $url,
            'alt' => $alt ?? 'image',
            'class' => 'img-fluid rounded',
            'width' => $width,
            'height' => $height,
            'loading' => 'lazy',
        ];

        $mergedAttributes = array_merge($defaultAttributes, $attributes);
        $attributeString = collect($mergedAttributes)
            ->map(fn ($value, $key): string => sprintf('%s="%s"', $key, htmlspecialchars((string) $value)))
            ->implode(' ');

        return sprintf('<img %s />', $attributeString);
    }

    /**
     * Upload a media file to the specified collection
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public static function upload(
        ?UploadedFile $media,
        mixed $model,
        string $collectionName,
        array $options = []
    ): void {
        if (! $media instanceof UploadedFile) {
            return;
        }

        throw_unless(method_exists($model, 'addMedia'), new InvalidArgumentException('Model must implement Spatie\MediaLibrary\HasMedia interface'));

        try {
            $fileName = sprintf(
                '%s-%s.%s',
                Str::uuid(),
                Str::slug($collectionName),
                $media->extension()
            );

            $mediaItem = $model->addMedia($media)->usingFileName($fileName);

            // Apply optional manipulations
            if (isset($options['manipulations']) && is_array($options['manipulations'])) {
                $mediaItem->withManipulations($options['manipulations']);
            }

            // Set custom properties
            if (isset($options['customProperties']) && is_array($options['customProperties'])) {
                $mediaItem->withCustomProperties($options['customProperties']);
            }

            // Set responsive images
            if (isset($options['responsive']) && $options['responsive'] === true) {
                $mediaItem->withResponsiveImages();
            }

            // Finally, add to collection
            $mediaItem->toMediaCollection($collectionName);

        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf('Failed to upload media file: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Delete media from a collection
     */
    public static function deleteMedia(mixed $model, string $collectionName): void
    {
        if (method_exists($model, 'clearMediaCollection')) {
            $model->clearMediaCollection($collectionName);
        }
    }

    /**
     * Check if file extension is allowed for images
     */
    public static function isAllowedImageExtension(string $extension): bool
    {
        return in_array(
            mb_strtolower('.'.mb_ltrim($extension, '.')),
            self::$allowedImageExtensions,
            true
        );
    }

    /**
     * Get all allowed extensions as a collection
     */
    public static function getAllowedExtensions(): Collection
    {
        return collect(self::$allowedImageExtensions)
            ->map(fn ($ext): string => mb_ltrim((string) $ext, '.'));
    }

    /**
     * Get file size in human-readable format
     */
    public static function getHumanFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
