<?php
/**
 * Transcoder plugin for Craft CMS 3.x
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\services;

use Craft;
use craft\base\Component;
use craft\console\Request;
use craft\elements\Asset;
use craft\volumes\Local;

use yii\base\Exception;

/**
 * @author    nystudio107
 * @package   Transcoder
 * @since     1.0.0
 */
class Transcoder extends Component
{
    // Protected Properties
    // =========================================================================

    // Suffixes to add to the generated filename params
    protected $suffixMap = [
        'frameRate' => 'fps',
        'bitRate' => 'bps',
        'height' => 'h',
        'width' => 'w',
        'timeInSecs' => 's',
    ];

    // Params that should be excluded from being included in the generated filename
    protected $excludeParams = [
        'fileSuffix',
        'sharpen'
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns a URL to the transcoded video or "" if it doesn't exist (at which
     * time it will create it). By default, the video format is always .mp4
     *
     * @param $filePath     path to the original video -OR- an Asset
     * @param $videoOptions array of options for the video
     *
     * @return string       URL of the transcoded video or ""
     */
    public function getVideoUrl($filePath, $videoOptions): string
    {

        $result = "";
        $filePath = $this->getAssetPath($filePath);

        if (file_exists($filePath)) {
            $destVideoPath = Craft::$app->config->get("transcoderPath", "transcoder");

            $videoOptions = $this->coalesceOptions("defaultVideoOptions", $videoOptions);

            // Build the basic command for ffmpeg
            $ffmpegCmd = Craft::$app->config->get("ffmpegPath", "transcoder")
                .' -i '.escapeshellarg($filePath)
                .' -vcodec libx264'
                .' -vprofile high'
                .' -preset slow'
                .' -crf 22'
                .' -c:a copy'
                .' -bufsize 1000k'
                .' -threads 0';

            // Set the framerate if desired
            if (!empty($videoOptions['frameRate'])) {
                $ffmpegCmd .= ' -r '.$videoOptions['frameRate'];
            }

            // Set the bitrate if desired
            if (!empty($videoOptions['bitRate'])) {
                $ffmpegCmd .= ' -b:v '.$videoOptions['bitRate'].' -maxrate '.$videoOptions['bitRate'];
            }

            // Adjust the scaling if desired
            $ffmpegCmd = $this->addScalingFfmpegArgs(
                $videoOptions,
                $ffmpegCmd
            );

            // Create the directory if it isn't there already
            if (!file_exists($destVideoPath)) {
                mkdir($destVideoPath);
            }

            $destVideoFile = $this->getFilename($filePath, $videoOptions);

            // File to store the video encoding progress in
            $progressFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$destVideoFile.".progress";

            // Assemble the destination path and final ffmpeg command
            $destVideoPath = $destVideoPath.$destVideoFile;
            $ffmpegCmd .= ' -f mp4 -y '.escapeshellarg($destVideoPath).' 1> '.$progressFile.' 2>&1 & echo $!';

            // Make sure there isn't a lockfile for this video already
            $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$destVideoFile.".lock";
            $oldPid = @file_get_contents($lockFile);
            if ($oldPid !== false) {
                exec("ps $oldPid", $ProcessState);
                if (count($ProcessState) >= 2) {
                    return $result;
                }
                // It's finished transcoding, so delete the lockfile and progress file
                @unlink($lockFile);
                @unlink($progressFile);
            }

            // If the video file already exists and hasn't been modified, return it.  Otherwise, start it transcoding
            if (file_exists($destVideoPath) && (filemtime($destVideoPath) >= filemtime($filePath))) {
                $result = Craft::$app->config->get("transcoderUrl", "transcoder").$destVideoFile;
            } else {
                // Kick off the transcoding
                $pid = shell_exec($ffmpegCmd);
                Craft::info($ffmpegCmd, __METHOD__);

                // Create a lockfile in tmp
                file_put_contents($lockFile, $pid);
            }
        }

        return $result;
    }

    /**
     * Returns a URL to a video thumbnail
     *
     * @param $filePath         path to the original video or an Asset
     * @param $thumbnailOptions array of options for the thumbnail
     *
     * @return string           URL of the video thumbnail
     */
    public function getVideoThumbnailUrl($filePath, $thumbnailOptions): string
    {

        $result = "";
        $filePath = $this->getAssetPath($filePath);

        if (file_exists($filePath)) {
            $destThumbnailPath = Craft::$app->config->get("transcoderPath", "transcoder");

            $thumbnailOptions = $this->coalesceOptions("defaultThumbnailOptions", $thumbnailOptions);

            // Build the basic command for ffmpeg
            $ffmpegCmd = Craft::$app->config->get("ffmpegPath", "transcoder")
                .' -i '.escapeshellarg($filePath)
                .' -vcodec mjpeg'
                .' -vframes 1';

            // Adjust the scaling if desired
            $ffmpegCmd = $this->addScalingFfmpegArgs(
                $thumbnailOptions,
                $ffmpegCmd
            );

            // Set the timecode to get the thumbnail from if desired
            if (!empty($thumbnailOptions['timeInSecs'])) {
                $timeCode = gmdate("H:i:s", $thumbnailOptions['timeInSecs']);
                $ffmpegCmd .= ' -ss '.$timeCode.'.00';
            }

            // Create the directory if it isn't there already
            if (!file_exists($destThumbnailPath)) {
                mkdir($destThumbnailPath);
            }

            $destThumbnailFile = $this->getFilename($filePath, $thumbnailOptions);

            // Assemble the destination path and final ffmpeg command
            $destThumbnailPath = $destThumbnailPath.$destThumbnailFile;
            $ffmpegCmd .= ' -f image2 -y '.escapeshellarg($destThumbnailPath).' >/dev/null 2>/dev/null';

            // If the thumbnail file already exists, return it.  Otherwise, generate it and return it
            if (!file_exists($destThumbnailPath)) {
                $shellOutput = shell_exec($ffmpegCmd);
                Craft::info($ffmpegCmd, __METHOD__);
            }
            $result = Craft::$app->config->get("transcoderUrl", "transcoder").$destThumbnailFile;
        }

        return $result;
    }

    /**
     * Extract information from a video
     *
     * @param $filePath
     *
     * @return array
     */
    public function getFileInfo($filePath): array
    {

        $result = null;
        $filePath = $this->getAssetPath($filePath);

        if (file_exists($filePath)) {
            // Build the basic command for ffprobe
            $ffprobeOptions = Craft::$app->config->get("ffprobeOptions", "transcoder");
            $ffprobeCmd = Craft::$app->config->get("ffprobePath", "transcoder")
                .' '.$ffprobeOptions
                .' '.escapeshellarg($filePath);

            $shellOutput = shell_exec($ffprobeCmd);
            Craft::info($ffprobeCmd, __METHOD__);
            $result = json_decode($shellOutput, true);
            Craft::info(print_r($result, true), __METHOD__);
        }

        return $result;
    }

    /**
     * Get the name of a video from a path and options
     *
     * @param $filePath
     * @param $videoOptions
     *
     * @return string
     */
    public function getVideoFilename($filePath, $videoOptions): string
    {
        $videoOptions = $this->coalesceOptions("defaultVideoOptions", $videoOptions);
        $result = $this->getFilename($filePath, $videoOptions);

        return $result;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Get the name of a file from a path and options
     *
     * @param $filePath
     * @param $options
     *
     * @return string
     */
    protected function getFilename($filePath, $options)
    {
        $filePath = $this->getAssetPath($filePath);
        $pathParts = pathinfo($filePath);
        $fileName = $pathParts['filename'];

        // Add our options to the file name
        foreach ($options as $key => $value) {
            if (!empty($value)) {
                $suffix = "";
                if (!empty($this->suffixMap[$key])) {
                    $suffix = $this->suffixMap[$key];
                }
                if (is_bool($value)) {
                    $value = $value ? $key : 'no'.$key;
                }
                if (!in_array($key, $this->excludeParams)) {
                    $fileName .= '_'.$value.$suffix;
                }
            }
        }
        $fileName .= $options['fileSuffix'];

        return $fileName;
    }

    /**
     * Extract a file system path if $filePath is an Asset object
     *
     * @param $filePath
     *
     * @return string
     * @throws Exception
     */
    protected function getAssetPath($filePath): string
    {
        // If we're passed an Asset, extract the path from it
        if (is_object($filePath) && ($filePath instanceof Asset)) {
            $asset = $filePath;
            $assetVolume = $asset->getVolume();

            if (!(($assetVolume instanceof Local) || is_subclass_of($assetVolume, Local::class))) {
                throw new Exception(
                    Craft::t('transcoder', 'Paths not available for non-local asset sources')
                );
            }

            $sourcePath = rtrim($assetVolume->path, DIRECTORY_SEPARATOR);
            $sourcePath .= strlen($sourcePath) ? DIRECTORY_SEPARATOR : '';
            $folderPath = rtrim($asset->getFolder()->path, DIRECTORY_SEPARATOR);
            $folderPath .= strlen($folderPath) ? DIRECTORY_SEPARATOR : '';

            $filePath = $sourcePath.$folderPath.$asset->filename;
        }

        return $filePath;
    }

    /**
     * Set the width & height if desired
     *
     * @param $options
     * @param $ffmpegCmd
     *
     * @return string
     */
    protected function addScalingFfmpegArgs($options, $ffmpegCmd): string
    {
        if (!empty($options['width']) && !empty($options['height'])) {
            // Handle "none", "crop", and "letterbox" aspectRatios
            if (!empty($options['aspectRatio'])) {
                switch ($options['aspectRatio']) {
                    // Scale to the appropriate aspect ratio, padding
                    case "letterbox":
                        $letterboxColor = "";
                        if (!empty($options['letterboxColor'])) {
                            $letterboxColor = ":color=".$options['letterboxColor'];
                        }
                        $aspectRatio = ':force_original_aspect_ratio=decrease'
                            .',pad='.$options['width'].':'.$options['height'].':(ow-iw)/2:(oh-ih)/2'
                            .$letterboxColor;
                        break;
                    // Scale to the appropriate aspect ratio, cropping
                    case "crop":
                        $aspectRatio = ':force_original_aspect_ratio=increase'
                            .',crop='.$options['width'].':'.$options['height'];
                        break;
                    // No aspect ratio scaling at all
                    default:
                        $aspectRatio = ':force_original_aspect_ratio=disable';
                        $options['aspectRatio'] = "none";
                        break;
                }
            }
            $sharpen = "";
            if (!empty($options['sharpen']) && ($options['sharpen'] !== false)) {
                $sharpen = ',unsharp=5:5:1.0:5:5:0.0';
            }
            $ffmpegCmd .= ' -vf "scale='
                .$options['width'].':'.$options['height']
                .$aspectRatio
                .$sharpen
                .'"';
        }

        return $ffmpegCmd;
    }

    /**
     * Combine the options arrays
     *
     * @param $defaultName
     * @param $options
     *
     * @return array
     */
    protected function coalesceOptions($defaultName, $options): array
    {
        // Default options
        $defaultOptions = Craft::$app->config->get($defaultName, "transcoder");

        // Coalesce the passed in $options with the $defaultOptions
        $options = array_merge($defaultOptions, $options);

        return $options;
    }
}
