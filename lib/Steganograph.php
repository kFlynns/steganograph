<?php

namespace KFlynns\Steganograph;

use Exception;
use GdImage;
use function chr;
use function fclose;
use function fgets;
use function flock;
use function floor;
use function fopen;
use function fwrite;
use function imagecolorallocate;
use function imagecolorat;
use function imagecreatefromstring;
use function imagedestroy;
use function imagepng;
use function imagesetpixel;
use function imagesx;
use function imagesy;
use function is_file;
use function is_readable;
use function is_resource;
use function is_writable;
use function ord;
use function pack;
use function stream_get_contents;
use function strlen;
use function touch;
use function unpack;

class Steganograph
{

    /** @var resource */
    protected $contentResource;

    /** @var GdImage|null  */
    protected ?GdImage $image = null;

    /** @var int  */
    protected int $imageWidth;

    /** @var int  */
    protected int $imageHeight;

    /**
     *
     */
    public function __destruct()
    {
        if (null !== $this->image)
        {
            imagedestroy($this->image);
        }
        $this->closeFileResource($this->contentResource);
    }

    /**
     * @param string $filePath
     * @param bool $writeMode
     * @return resource
     * @throws Exception
     */
    protected function getFileResource (string $filePath, bool $writeMode = false)
    {
        touch($filePath);
        if (!is_file($filePath) || !is_readable($filePath))
        {
            throw new Exception (
                self::class
                . ': the file under "'
                . $filePath
                . '" is not readable.'
            );
        }
        $resource = fopen($filePath, 'r+');
        if (!is_resource($resource))
        {
            throw new Exception(
                self::class
                . ': could not open file under "'
                . $filePath
                . '".'
            );
        }
        flock($resource, \LOCK_EX);
        return $resource;
    }

    /**
     * @param resource $resource
     * @return void
     */
    protected function closeFileResource ($resource): void
    {
        if (!is_resource($resource))
        {
            return;
        }
        flock($resource, \LOCK_UN);
        fclose($resource);
    }

    /**
     * @param string $imageFilePath
     * @param string $contentFilePath
     * @return void
     * @throws Exception
     */
    protected function openFiles (string $imageFilePath, string $contentFilePath): void
    {
        touch($contentFilePath);
        $this->contentResource = $this->getFileResource($contentFilePath);
        $imageResource = $this->getFileResource($imageFilePath);
        $image = imagecreatefromstring(
            stream_get_contents($imageResource)
        );
        $this->closeFileResource($imageResource);
        if (false === $image)
        {
            throw new \Exception(self::class . ': could not read image under "' . $imageFilePath . '".');
        }
        $this->image = $image;
        $this->imageHeight = imagesy($this->image);
        $this->imageWidth = imagesx($this->image);
    }

    /**
     * @param string $outputFilePath
     * @return void
     * @throws Exception
     */
    protected function encode (string $outputFilePath): void
    {
        touch($outputFilePath);
        if (!is_writable($outputFilePath))
        {
            throw new \Exception(self::class . ': could not open file for writing.');
        }
        $pixelPointer = 0;
        $fileHeader = pack('L', (int)\fstat($this->contentResource)['size']);
        while (($buffer = fgets($this->contentResource, 0xff)) !== false)
        {
            if (null !== $fileHeader)
            {
                $buffer = $fileHeader . $buffer;
                $fileHeader = null;
            }
            /** @var int $i */
            for ($i = 0; $i < strlen($buffer); $i++)
            {
                $char = ord($buffer[$i]);
                $bitMask = 1;
                do
                {
                    $y = floor($pixelPointer / $this->imageWidth);
                    $x = $pixelPointer - $y * $this->imageWidth;
                    $color = imagecolorat(
                        $this->image,
                        $x, $y
                    );
                    $pixels = [
                        ($color >> 0x10) & 0xff,
                        ($color >> 0x08) & 0xff,
                                  $color & 0xff
                    ];
                    $pixel = &$pixels[$pixelPointer++ % 3];
                    $pixel -= ($pixel % 2) + ($char & $bitMask) / $bitMask;
                    imagesetpixel(
                        $this->image,
                        $x, $y,
                        imagecolorallocate(
                            $this->image,
                            $pixels[0],
                            $pixels[1],
                            $pixels[2]
                        )
                    );
                } while (($bitMask *= 2) <= 0x80);
            }
        }
        imagepng($this->image, $outputFilePath);
    }

    /**
     * @return void
     */
    protected function decode(): void
    {
        $pixelPointer = 0;
        $bit = 1;
        $byte = 0;
        $writeBuffer = '';
        $header = '';
        $fileSize = null;
        /** @var int $y */
        for ($y = 0; $y < $this->imageHeight; $y++)
        {
            /** @var int $x */
            for ($x = 0; $x < $this->imageWidth; $x++)
            {
                $color = imagecolorat(
                    $this->image,
                    $x, $y
                );
                $byte += $bit * (($color >> ((2 - $pixelPointer++ % 3) * 8)) & 0xff % 2);
                $bit *= 2;
                if ($bit > 0x80)
                {
                    if (strlen($header) < 4)
                    {
                        $header .= chr($byte);
                        $bit = 1;
                        $byte = 0;
                        continue;
                    }
                    if (null === $fileSize)
                    {
                        $fileSize = (int)unpack('L', $header)[1];
                    }
                    $writeBuffer .= chr($byte);
                    $bit = 1;
                    $byte = 0;
                    if (--$fileSize === 0)
                    {
                        break 2;
                    }
                    if (strlen($writeBuffer) === 0xff)
                    {
                        fwrite($this->contentResource, $writeBuffer);
                        $writeBuffer = '';
                    }
                }
            }
        }
        fwrite($this->contentResource, $writeBuffer);
    }

    /**
     * @param string $imageFilePath
     * @param string $contentFilePath
     * @param string $outputFilePath
     * @return void
     * @throws Exception
     */
    public function packFileIntoImage (
        string $imageFilePath,
        string $contentFilePath,
        string $outputFilePath
    ): void {
        $this
            ->closeFileResource($this->contentResource);
        $this->openFiles(
            $imageFilePath,
            $contentFilePath
        );
        $this->encode($outputFilePath);
    }

    /**
     * @throws Exception
     */
    public function extractFileFromImage (
        string $imageFilePath,
        string $outputFilePath
    ): void {
        $this->closeFileResource($this->contentResource);
        $this->openFiles(
            $imageFilePath,
            $outputFilePath
        );
        $this->decode();
    }

}