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

use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\OutOfBoundsException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractLayers;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\PaletteInterface;
use Jcupitt\Vips\Exception;

class Layers extends AbstractLayers
{
    /**
     * @var Image
     */
    private $image;
    /**
     * @var \Jcupitt\Vips\Image
     */
    private $resource;
    /**
     * @var int
     */
    private $offset = 0;
    /**
     * @var array
     */
    private $layers = [];

    private $palette;

    public function __construct(Image $image, PaletteInterface $palette, \Jcupitt\Vips\Image $resource)
    {
        $this->image = $image;
        $this->resource = $resource;
        $this->palette = $palette;
    }

    /**
     * {@inheritdoc}
     */
    public function merge()
    {
        foreach ($this->layers as $offset => $image) {
            try {
                $this->resource->setIteratorIndex($offset);
                $this->resource->setImage($image->getImagick());
            } catch (\ImagickException $e) {
                throw new RuntimeException('Failed to substitute layer', $e->getCode(), $e);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function animate($format, $delay, $loops)
    {
        if ('gif' !== strtolower($format)) {
            throw new InvalidArgumentException('Animated picture is currently only supported on gif');
        }

        if (!is_int($loops) || $loops < 0) {
            throw new InvalidArgumentException('Loops must be a positive integer.');
        }

        if (null !== $delay && (!is_int($delay) || $delay < 0)) {
            throw new InvalidArgumentException('Delay must be either null or a positive integer.');
        }

        try {
            foreach ($this as $offset => $layer) {
                $this->resource->setIteratorIndex($offset);
                $this->resource->setFormat($format);

                if (null !== $delay) {
                    $layer->getImagick()->setImageDelay($delay / 10);
                    $layer->getImagick()->setImageTicksPerSecond(100);
                }
                $layer->getImagick()->setImageIterations($loops);

                $this->resource->setImage($layer->getImagick());
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Failed to animate layers', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function coalesce()
    {
        try {
            $coalescedResource = $this->resource->coalesceImages();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Failed to coalesce layers', $e->getCode(), $e);
        }

        $count = $coalescedResource->getNumberImages();
        for ($offset = 0; $offset < $count; ++$offset) {
            try {
                $coalescedResource->setIteratorIndex($offset);
                $this->layers[$offset] = new Image($coalescedResource->getImage(), $this->palette, new MetadataBag());
            } catch (\ImagickException $e) {
                throw new RuntimeException('Failed to retrieve layer', $e->getCode(), $e);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return $this->extractAt($this->offset);
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->offset;
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        ++$this->offset;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->offset = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return $this->offset < count($this);
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        try {
            return $this->resource;
        } catch (\ImagickException $e) {
            throw new RuntimeException('Failed to count the number of layers', $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset)
    {
        return is_int($offset) && $offset >= 0 && $offset < count($this);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset)
    {
        return $this->extractAt($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $image)
    {
        if (!$image instanceof Image) {
            throw new InvalidArgumentException('Only an Imagick Image can be used as layer');
        }

        if (null === $offset) {
            $offset = count($this) - 1;
        } else {
            if (!is_int($offset)) {
                throw new InvalidArgumentException('Invalid offset for layer, it must be an integer');
            }

            if (count($this) < $offset || 0 > $offset) {
                throw new OutOfBoundsException(sprintf('Invalid offset for layer, it must be a value between 0 and %d, %d given', count($this), $offset));
            }

            if (isset($this[$offset])) {
                unset($this[$offset]);
                $offset = $offset - 1;
            }
        }

        $frame = $image->getImagick();

        try {
            if (count($this) > 0) {
                $this->resource->setIteratorIndex($offset);
            }
            $this->resource->addImage($frame);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Unable to set the layer', $e->getCode(), $e);
        }

        $this->layers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        try {
            $this->extractAt($offset);
        } catch (RuntimeException $e) {
            return;
        }

        try {
            $this->resource->setIteratorIndex($offset);
            $this->resource->removeImage();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Unable to remove layer', $e->getCode(), $e);
        }
    }

    /**
     * Tries to extract layer at given offset.
     *
     * @param int $offset
     *
     * @throws RuntimeException
     *
     * @return Image
     */
    private function extractAt($offset)
    {
        if (!isset($this->layers[$offset])) {
            try {
                $this->layers[$offset] = new Image($this->resource->getImage(), $this->palette, new MetadataBag());
            } catch (Exception $e) {
                throw new RuntimeException(sprintf('Failed to extract layer %d', $offset), $e->getCode(), $e);
            }
        }

        return $this->layers[$offset];
    }
}
