<?php

/*
 * This file is part of the Imagine package.
 *
 * (c) Bulat Shakirzyanov <mallluhuct@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Exception\NotSupportedException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\BoxInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Palette\Grayscale;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\Extend;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Imagine implementation using the Vips PHP extension
 */
class Imagine extends AbstractImagine
{
    /**
     * @throws RuntimeException
     */
    public function __construct()
    {
        if (!extension_loaded('vips')) {
            throw new RuntimeException('Vips not installed');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open($path)
    {
        $path = $this->checkPath($path);

        try {
            $vips = VipsImage::newFromFile($path);
            return new Image($vips, self::createPalette($vips), $this->getMetadataReader()->readFile($path));
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('Unable to open image %s', $path), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create(BoxInterface $size, ColorInterface $color = null)
    {
        $vips = Image::generateImage($size, $color);
        return new Image($vips, self::createPalette($vips), new MetadataBag());
    }

    /**
     * {@inheritdoc}
     */
    public function load($string)
    {
        try {
            $vips = VipsImage::newFromBuffer($string);
            return new Image($vips, self::createPalette($vips), $this->getMetadataReader()->readData($string));
        } catch (\Exception $e) {
            throw new RuntimeException('Could not load image from string', $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Variable does not contain a stream resource');
        }

        $content = stream_get_contents($resource);
        return $this->load($content);
    }

    /**
     * {@inheritdoc}
     */
    public function font($file, $size, ColorInterface $color)
    {
        return new Font(null, $file, $size, $color);
    }

    /**
     * Returns the palette corresponding to an VIPS resource colorspace
     *
     * @param VipsImage $vips
     *
     * @return CMYK|Grayscale|RGB
     *
     * @throws NotSupportedException
     */
    public static function createPalette(VipsImage $vips)
    {
        switch ($vips->interpretation) {
            case Interpretation::RGB:
            case Interpretation::RGB16:
            case Interpretation::SRGB:
                return new RGB();
            case Interpretation::CMYK:
                return new CMYK();
            case Interpretation::GREY16:
                return new Grayscale();
            default:
                throw new NotSupportedException('Only RGB and CMYK colorspace are currently supported');
        }
    }


}
