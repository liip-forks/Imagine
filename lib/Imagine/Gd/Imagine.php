<?php

/*
 * This file is part of the Imagine package.
 *
 * (c) Bulat Shakirzyanov <mallluhuct@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Gd;

use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\RuntimeException;
use Imagine\Factory\ClassFactoryInterface;
use Imagine\File\LoaderInterface;
use Imagine\Image\AbstractImagine;
use Imagine\Image\BoxInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\Color\RGB as RGBColor;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;

/**
 * Imagine implementation using the GD library.
 */
final class Imagine extends AbstractImagine
{
    /**
     * @throws RuntimeException
     */
    public function __construct()
    {
        $this->requireGdVersion('2.0.1');
    }

    /**
     * {@inheritdoc}
     */
    public function create(BoxInterface $size, ColorInterface $color = null)
    {
        $width = $size->getWidth();
        $height = $size->getHeight();

        $resource = imagecreatetruecolor($width, $height);

        if (false === $resource) {
            throw new RuntimeException('Create operation failed');
        }

        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = $color ? $color : $palette->color('fff');

        if (!$color instanceof RGBColor) {
            throw new InvalidArgumentException('GD driver only supports RGB colors');
        }

        $index = imagecolorallocatealpha($resource, $color->getRed(), $color->getGreen(), $color->getBlue(), round(127 * (100 - $color->getAlpha()) / 100));

        if (false === $index) {
            throw new RuntimeException('Unable to allocate color');
        }

        if (false === imagefill($resource, 0, 0, $index)) {
            throw new RuntimeException('Could not set background color fill');
        }

        if ($color->getAlpha() >= 95) {
            imagecolortransparent($resource, $index);
        }

        return $this->wrap($resource, $palette, new MetadataBag());
    }

    /**
     * {@inheritdoc}
     */
    public function open($path)
    {
        $loader = $path instanceof LoaderInterface ? $path : $this->getClassFactory()->createFileLoader($path);
        $path = $loader->getPath();

        $resource = @imagecreatefromstring($loader->getData());

        if (!is_resource($resource)) {
            throw new RuntimeException(sprintf('Unable to open image %s', $path));
        }

        return $this->wrap($resource, new RGB(), $this->getMetadataReader()->readFile($loader));
    }

    /**
     * {@inheritdoc}
     */
    public function load($string)
    {
        return $this->doLoad($string, $this->getMetadataReader()->readData($string));
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

        if (false === $content) {
            throw new InvalidArgumentException('Cannot read resource content');
        }

        return $this->doLoad($content, $this->getMetadataReader()->readData($content, $resource));
    }

    /**
     * {@inheritdoc}
     */
    public function font($file, $size, ColorInterface $color)
    {
        return $this->getClassFactory()->createFont(ClassFactoryInterface::HANDLE_GD, $file, $size, $color);
    }

    private function wrap($resource, PaletteInterface $palette, MetadataBag $metadata)
    {
        if (!imageistruecolor($resource)) {
            if (function_exists('imagepalettetotruecolor')) {
                if (false === imagepalettetotruecolor($resource)) {
                    throw new RuntimeException('Could not convert a palette based image to true color');
                }
            } else {
                list($width, $height) = array(imagesx($resource), imagesy($resource));

                // create transparent truecolor canvas
                $truecolor = imagecreatetruecolor($width, $height);
                $transparent = imagecolorallocatealpha($truecolor, 255, 255, 255, 127);

                imagealphablending($truecolor, false);
                imagefilledrectangle($truecolor, 0, 0, $width, $height, $transparent);
                imagealphablending($truecolor, false);

                imagecopy($truecolor, $resource, 0, 0, 0, 0, $width, $height);

                imagedestroy($resource);
                $resource = $truecolor;
            }
        }

        if (false === imagealphablending($resource, false) || false === imagesavealpha($resource, true)) {
            throw new RuntimeException('Could not set alphablending, savealpha and antialias values');
        }

        if (function_exists('imageantialias')) {
            imageantialias($resource, true);
        }

        return $this->getClassFactory()->createImage(ClassFactoryInterface::HANDLE_GD, $resource, $palette, $metadata);
    }

    private function requireGdVersion($version)
    {
        if (!function_exists('gd_info')) {
            throw new RuntimeException('Gd not installed');
        }
        if (version_compare(GD_VERSION, $version, '<')) {
            throw new RuntimeException(sprintf('GD2 version %s or higher is required, %s provided', $version, GD_VERSION));
        }
    }

    private function doLoad($string, MetadataBag $metadata)
    {
        $resource = @imagecreatefromstring($string);

        if (!is_resource($resource)) {
            throw new RuntimeException('An image could not be created from the given input');
        }

        return $this->wrap($resource, new RGB(), $metadata);
    }
}
