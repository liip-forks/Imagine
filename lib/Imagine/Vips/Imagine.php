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
use Imagine\Image\Palette\RGB;
use Imagine\Image\Palette\Grayscale;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Imagine implementation using the Imagick PHP extension
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
            return new Image($vips, $this->createPalette($vips), $this->getMetadataReader()->readData($string));
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('Unable to open image %s', $path), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create(BoxInterface $size, ColorInterface $color = null)
    {

        //FIXME: no idea how to do that ;)
        // maybe something like
        $width  = $size->getWidth();
        $height = $size->getHeight();

        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = null !== $color ? $color : $palette->color('fff');

        try {
            $pixel = new \ImagickPixel((string) $color);
            $pixel->setColorValue(\Imagick::COLOR_ALPHA, $color->getAlpha() / 100);

            $imagick = new \Imagick();
            $imagick->newImage($width, $height, $pixel);
            $imagick->setImageMatte(true);
            $imagick->setImageBackgroundColor($pixel);

            if (version_compare('6.3.1', $this->getVersion($imagick)) < 0) {
                // setImageOpacity was replaced with setImageAlpha in php-imagick v3.4.3
                if (method_exists($imagick, 'setImageAlpha')) {
                    $imagick->setImageAlpha($pixel->getColorValue(\Imagick::COLOR_ALPHA));
                } else {
                    $imagick->setImageOpacity($pixel->getColorValue(\Imagick::COLOR_ALPHA));
                }
            }

            $pixel->clear();
            $pixel->destroy();

            return new Image($imagick, $palette, new MetadataBag());
        } catch (\ImagickException $e) {
            throw new RuntimeException('Could not create empty image', $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load($string)
    {
        try {
            $vips = VipsImage::newFromBuffer($string);
            return new Image($vips, $this->createPalette($vips), $this->getMetadataReader()->readData($string));
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
     * Returns the palette corresponding to an \Imagick resource colorspace
     *
     * @param VipsImage $vips
     *
     * @return CMYK|Grayscale|RGB
     *
     * @throws NotSupportedException
     */
    private function createPalette(VipsImage $vips)
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
