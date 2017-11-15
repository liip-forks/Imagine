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

use Imagine\Exception\OutOfBoundsException;
use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImage;
use Imagine\Image\Box;
use Imagine\Image\BoxInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Fill\FillInterface;
use Imagine\Image\Fill\Gradient\Horizontal;
use Imagine\Image\Fill\Gradient\Linear;
use Imagine\Image\Point;
use Imagine\Image\PointInterface;
use Imagine\Image\ProfileInterface;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\PaletteInterface;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Kernel;


/**
 * Image implementation using the Imagick PHP extension
 */
class Image extends AbstractImage
{
    /**
     * @var \Jcupitt\Vips\Image
     */
    private $vips;
    /**
     * @var Layers
     */
    private $layers;
    /**
     * @var PaletteInterface
     */
    private $palette;

    /**
     * @var Boolean
     */
    private static $supportsColorspaceConversion;

    private static $colorspaceMapping = array(
        PaletteInterface::PALETTE_CMYK      => \Imagick::COLORSPACE_CMYK,
        PaletteInterface::PALETTE_RGB       => \Imagick::COLORSPACE_RGB,
        PaletteInterface::PALETTE_GRAYSCALE => \Imagick::COLORSPACE_GRAY,
    );

    /**
     * Constructs a new Image instance
     *
     * @param \Jcupitt\Vips\Image         $imagick
     * @param PaletteInterface $palette
     * @param MetadataBag      $metadata
     */
    public function __construct(VipsImage $vips, PaletteInterface $palette, MetadataBag $metadata)
    {
        $this->vips = $vips;

        $this->metadata = $metadata;
        $this->detectColorspaceConversionSupport();
        if (self::$supportsColorspaceConversion) {
            //FIXME:: support this..
            $this->setColorspace($palette);
        }
        $this->palette = $palette;
        // FIXME:: layers..
        //$this->layers = new Layers($this, $this->palette, $this->vips);
    }

    /**
     * Destroys allocated imagick resources
     */
    public function __destruct()
    {
        if ($this->vips instanceof \Imagick) {
            $this->vips->clear();
            $this->vips->destroy();
        }
    }

    /**
     * Returns the underlying \Jcupitt\Vips\Image instance
     *
     * @return \Jcupitt\Vips\Image
     */
    public function getVips()
    {
        return $this->vips;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function copy()
    {
        try {
            if (version_compare(phpversion("imagick"), "3.1.0b1", ">=") || defined("HHVM_VERSION")) {
                $clone = clone $this->vips;
            } else {
                $clone = $this->vips->clone();
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Copy operation failed', $e->getCode(), $e);
        }

        return new self($clone, $this->palette, clone $this->metadata);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function crop(PointInterface $start, BoxInterface $size)
    {
        //FIXME. this gives an error when $size is biger than image, does not with imagick
        if (!$start->in($this->getSize())) {
            throw new OutOfBoundsException('Crop coordinates must start at minimum 0, 0 position from top left corner, crop height and width must be positive integers and must not exceed the current image borders');
        }
        try {
            //FIXME: Layers support
            /*if ($this->layers()->count() > 1) {
                // Crop each layer separately
                $this->vips = $this->vips->coalesceImages();
                foreach ($this->vips as $frame) {
                    $frame->cropImage($size->getWidth(), $size->getHeight(), $start->getX(), $start->getY());
                    // Reset canvas for gif format
                    $frame->setImagePage(0, 0, 0, 0);
                }
                $this->vips = $this->vips->deconstructImages();
            } else {*/
                $this->vips = $this->vips->crop($start->getX(), $start->getY(), $size->getWidth(), $size->getHeight());
                //$this->vips->cropImage(, , , );
                // Reset canvas for gif format
            // FIXME?
                //$this->vips->setImagePage(0, 0, 0, 0);
            //}
        } catch (Jcupitt\Vips\Exception $e) {
            throw new RuntimeException('Crop operation failed', $e->getCode(), $e);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function flipHorizontally()
    {
        try {
            $this->vips->flopImage();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Horizontal Flip operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function flipVertically()
    {
        try {
            $this->vips->flipImage();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Vertical flip operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function strip()
    {
        //FIXME: whatever strip metadata
        return $this;
        try {
            try {
                $this->profile($this->palette->profile());
            } catch (\Exception $e) {
                // here we discard setting the profile as the previous incorporated profile
                // is corrupted, let's now strip the image
            }
            $this->vips->stripImage();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Strip operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function paste(ImageInterface $image, PointInterface $start)
    {
        if (!$image instanceof self) {
            throw new InvalidArgumentException(sprintf('Imagick\Image can only paste() Imagick\Image instances, %s given', get_class($image)));
        }

        if (!$this->getSize()->contains($image->getSize(), $start)) {
            throw new OutOfBoundsException('Cannot paste image of the given size at the specified position, as it moves outside of the current image\'s box');
        }

        try {
            $this->vips->compositeImage($image->imagick, \Imagick::COMPOSITE_DEFAULT, $start->getX(), $start->getY());
        } catch (\ImagickException $e) {
            throw new RuntimeException('Paste operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resize(BoxInterface $size, $filter = ImageInterface::FILTER_UNDEFINED)
    {
        try {
            //TODO: check if this actually works and implement layers
            if (count($this->vips) > 1) {
                $this->vips = $this->vips->coalesceImages();
                foreach ($this->vips as $frame) {
                    $frame->resizeImage($size->getWidth(), $size->getHeight(), $this->getFilter($filter), 1);
                }
                $this->vips = $this->vips->deconstructImages();
            } else {
                //FIXME: we only need to do this, if it has visible alpha, not just an alpha channel
                if ($this->vips->hasAlpha()) {
                    $this->vips = $this->vips->premultiply();
                }
                $this->vips = $this->vips->resize( $size->getWidth() / $this->vips->width, ['vscale' => $size->getHeight() / $this->vips->height]);
                if ($this->vips->hasAlpha()) {
                    $this->vips = $this->vips->unpremultiply();
                }

                //$this->vips = $this->vips->premultiply();
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Resize operation failed', $e->getCode(), $e);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function rotate($angle, ColorInterface $background = null)
    {
        $color = $background ? $background : $this->palette->color('fff');

        try {
            $pixel = $this->getColor($color);
            // if the input image doesn't have an alpha channel, we need to add one
            //  ImageMagick 7 (?) seems not to do that automatically anymore.
            if ($this->vips->getImageAlphaChannel() == \Imagick::ALPHACHANNEL_UNDEFINED) {
                $this->vips->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
            }
            $this->vips->rotateimage($pixel, $angle);

            $pixel->clear();
            $pixel->destroy();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Rotate operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function save($path = null, array $options = array())
    {
        $path = null === $path ? $this->vips->getImageFilename() : $path;
        if (null === $path) {
            throw new RuntimeException('You can omit save path only if image has been open from a file');
        }

        try {
            $this->prepareOutput($options, $path);
            $this->vips->writeImages($path, true);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Save operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function show($format, array $options = array())
    {
        header('Content-type: '.$this->getMimeType($format));
        echo $this->get($format, $options);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get($format, array $options = array())
    {
        try {
            $options['format'] = $format;
            $this->prepareOutput($options);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Get operation failed', $e->getCode(), $e);
        }
        if ($format == 'jpg' || $format == 'jpeg') {
            return $this->vips->jpegsave_buffer(['strip' => true, 'Q' => $options['jpeg_quality'], 'interlace' => true]);
        }
        else if ($format == 'png') {
            return $this->vips->pngsave_buffer(['strip' => true]);
        }
        //FIXME, webp_quality and webp_lossless
        else if ($format == 'webp') {
            return $this->vips->webpsave_buffer(['strip' => true]);
        }
        else {
            //fallback to imagemagick, not sure pngsave is the best and fastest solution
            $imagickine = new \Imagine\Imagick\Imagine();
            $imagick = $imagickine->load($this->vips->pngsave_buffer(['interlace' => false]));
            return $imagick->get($format, $options);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function interlace($scheme)
    {
        static $supportedInterlaceSchemes = array(
            ImageInterface::INTERLACE_NONE      => \Imagick::INTERLACE_NO,
            ImageInterface::INTERLACE_LINE      => \Imagick::INTERLACE_LINE,
            ImageInterface::INTERLACE_PLANE     => \Imagick::INTERLACE_PLANE,
            ImageInterface::INTERLACE_PARTITION => \Imagick::INTERLACE_PARTITION,
        );

        if (!array_key_exists($scheme, $supportedInterlaceSchemes)) {
            throw new InvalidArgumentException('Unsupported interlace type');
        }
//FIXME: implement...
//        $this->vips->setInterlaceScheme($supportedInterlaceSchemes[$scheme]);

        return $this;
    }

    /**
     * @param array  $options
     * @param string $path
     */
    private function prepareOutput(array $options, $path = null)
    {
        if (isset($options['format'])) {
           // $this->vips->format = $options['format'];
            //$this->vips->setImageFormat($options['format']);
        }
        // FIXME: layer support
        /*
        if (isset($options['animated']) && true === $options['animated']) {
            $format = isset($options['format']) ? $options['format'] : 'gif';
            $delay = isset($options['animated.delay']) ? $options['animated.delay'] : null;
            $loops = isset($options['animated.loops']) ? $options['animated.loops'] : 0;

            $options['flatten'] = false;

            $this->layers->animate($format, $delay, $loops);
        } else {
            $this->layers->merge();
        }*/
        $this->applyImageOptions($this->vips, $options, $path);

        // flatten only if image has multiple layers
        // FIXME:: flatten
        /*if ((!isset($options['flatten']) || $options['flatten'] === true) && count($this->layers) > 1) {
            $this->flatten();
        }*/
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->get('png');
    }

    /**
     * {@inheritdoc}
     */
    public function draw()
    {
        return new Drawer($this->vips);
    }

    /**
     * {@inheritdoc}
     */
    public function effects()
    {
        return new Effects($this->vips);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize()
    {
        try {
/*            $i = $this->vips->getIteratorIndex();
            $this->vips->rewind();*/
            $width  = $this->vips->width;
            $height = $this->vips->height;
            //$this->vips->setIteratorIndex($i);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Could not get size', $e->getCode(), $e);
        }

        return new Box($width, $height);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function applyMask(ImageInterface $mask)
    {
        if (!$mask instanceof self) {
            throw new InvalidArgumentException('Can only apply instances of Imagine\Imagick\Image as masks');
        }

        $size = $this->getSize();
        $maskSize = $mask->getSize();

        if ($size != $maskSize) {
            throw new InvalidArgumentException(sprintf('The given mask doesn\'t match current image\'s size, Current mask\'s dimensions are %s, while image\'s dimensions are %s', $maskSize, $size));
        }

        $mask = $mask->mask();
        $mask->imagick->negateImage(true);

        try {
            // remove transparent areas of the original from the mask
            $mask->imagick->compositeImage($this->vips, \Imagick::COMPOSITE_DSTIN, 0, 0);
            $this->vips->compositeImage($mask->imagick, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);

            $mask->imagick->clear();
            $mask->imagick->destroy();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Apply mask operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function mask()
    {
        $mask = $this->copy();

        try {
            $mask->imagick->modulateImage(100, 0, 100);
            $mask->imagick->setImageMatte(false);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Mask operation failed', $e->getCode(), $e);
        }

        return $mask;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function fill(FillInterface $fill)
    {
        try {
            if ($this->isLinearOpaque($fill)) {
                $this->applyFastLinear($fill);
            } else {
                $iterator = $this->vips->getPixelIterator();

                foreach ($iterator as $y => $pixels) {
                    foreach ($pixels as $x => $pixel) {
                        $color = $fill->getColor(new Point($x, $y));

                        $pixel->setColor((string) $color);
                        $pixel->setColorValue(\Imagick::COLOR_ALPHA, $color->getAlpha() / 100);
                    }

                    $iterator->syncIterator();
                }
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Fill operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function histogram()
    {
        try {
            $pixels = $this->vips->getImageHistogram();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Error while fetching histogram', $e->getCode(), $e);
        }

        $image = $this;

        return array_map(function (\ImagickPixel $pixel) use ($image) {
            return $image->pixelToColor($pixel);
        },$pixels);
    }

    /**
     * {@inheritdoc}
     */
    public function getColorAt(PointInterface $point)
    {
        if (!$point->in($this->getSize())) {
            throw new RuntimeException(sprintf('Error getting color at point [%s,%s]. The point must be inside the image of size [%s,%s]', $point->getX(), $point->getY(), $this->getSize()->getWidth(), $this->getSize()->getHeight()));
        }

        try {
            $pixel = $this->vips->getImagePixelColor($point->getX(), $point->getY());
        } catch (\ImagickException $e) {
            throw new RuntimeException('Error while getting image pixel color', $e->getCode(), $e);
        }

        return $this->pixelToColor($pixel);
    }

    /**
     * Returns a color given a pixel, depending the Palette context
     *
     * Note : this method is public for PHP 5.3 compatibility
     *
     * @param \ImagickPixel $pixel
     *
     * @return ColorInterface
     *
     * @throws InvalidArgumentException In case a unknown color is requested
     */
    public function pixelToColor(\ImagickPixel $pixel)
    {
        static $colorMapping = array(
            ColorInterface::COLOR_RED     => \Imagick::COLOR_RED,
            ColorInterface::COLOR_GREEN   => \Imagick::COLOR_GREEN,
            ColorInterface::COLOR_BLUE    => \Imagick::COLOR_BLUE,
            ColorInterface::COLOR_CYAN    => \Imagick::COLOR_CYAN,
            ColorInterface::COLOR_MAGENTA => \Imagick::COLOR_MAGENTA,
            ColorInterface::COLOR_YELLOW  => \Imagick::COLOR_YELLOW,
            ColorInterface::COLOR_KEYLINE => \Imagick::COLOR_BLACK,
            // There is no gray component in \Imagick, let's use one of the RGB comp
            ColorInterface::COLOR_GRAY    => \Imagick::COLOR_RED,
        );

        $alpha = $this->palette->supportsAlpha() ? (int) round($pixel->getColorValue(\Imagick::COLOR_ALPHA) * 100) : null;
        $palette = $this->palette();

        return $this->palette->color(array_map(function ($color) use ($palette, $pixel, $colorMapping) {
            if (!isset($colorMapping[$color])) {
                throw new InvalidArgumentException(sprintf('Color %s is not mapped in Imagick', $color));
            }
            $multiplier = 255;
            if ($palette->name() === PaletteInterface::PALETTE_CMYK) {
                $multiplier = 100;
            }

            return $pixel->getColorValue($colorMapping[$color]) * $multiplier;
        }, $this->palette->pixelDefinition()), $alpha);
    }

    /**
     * {@inheritdoc}
     */
    public function layers()
    {
        return $this->layers;
    }

    /**
     * {@inheritdoc}
     */
    public function usePalette(PaletteInterface $palette)
    {
        if (!isset(self::$colorspaceMapping[$palette->name()])) {
            throw new InvalidArgumentException(sprintf('The palette %s is not supported by Imagick driver', $palette->name()));
        }

/* FIXME implement pallete support.. */
return $this;
         if ($this->palette->name() === $palette->name()) {
            return $this;
        }

        if (!self::$supportsColorspaceConversion) {
            throw new RuntimeException('Your version of Imagick does not support colorspace conversions.');
        }

        try {
            try {
                $hasICCProfile = (Boolean) $this->vips->getImageProfile('icc');
            } catch (\ImagickException $e) {
                $hasICCProfile = false;
            }

            if (!$hasICCProfile) {
                $this->profile($this->palette->profile());
            }

            $this->profile($palette->profile());
            $this->setColorspace($palette);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Failed to set colorspace', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function palette()
    {
        return $this->palette;
    }

    /**
     * {@inheritdoc}
     */
    public function profile(ProfileInterface $profile)
    {
        try {
            $this->vips->profileImage('icc', $profile->data());
        } catch (\ImagickException $e) {
            throw new RuntimeException(sprintf('Unable to add profile %s to image', $profile->name()), $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * Internal
     *
     * Flatten the image.
     */
    private function flatten()
    {
        /**
         * @see https://github.com/mkoppanen/imagick/issues/45
         */
        try {
            if (method_exists($this->vips, 'mergeImageLayers') && defined('Imagick::LAYERMETHOD_UNDEFINED')) {
                $this->vips = $this->vips->mergeImageLayers(\Imagick::LAYERMETHOD_UNDEFINED);
            } elseif (method_exists($this->vips, 'flattenImages')) {
                $this->vips = $this->vips->flattenImages();
            }
        } catch (\ImagickException $e) {
            throw new RuntimeException('Flatten operation failed', $e->getCode(), $e);
        }
    }

    /**
     * Internal
     *
     * Applies options before save or output
     *
     * @param \Imagick $image
     * @param array    $options
     * @param string   $path
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function applyImageOptions(VipsImage $vips, array $options, $path)
    {
        return;
        // FIXME: apply all those optoins...
        if (isset($options['format'])) {
            $format = $options['format'];
        } elseif ('' !== $extension = pathinfo($path, \PATHINFO_EXTENSION)) {
            $format = $extension;
        } else {
            $format = pathinfo($vips->getImageFilename(), \PATHINFO_EXTENSION);
        }

        $format = strtolower($format);

        $options = $this->updateSaveOptions($options);

        if (isset($options['jpeg_quality']) && in_array($format, array('jpeg', 'jpg', 'pjpeg'))) {
            $vips->setImageCompressionQuality($options['jpeg_quality']);
        }

        if (isset($options['webp_quality']) && in_array($format, array('webp'))) {
            $vips->setImageCompressionQuality($options['webp_quality']);
        }
        //FIXME, support webp lossless. only needs to be fixed in self::get()
        if (isset($options['webp_lossless']) && in_array($format, array('webp'))) {
            $image->setOption('webp:lossless', $options['webp_lossless']);
        }


        if ((isset($options['png_compression_level']) || isset($options['png_compression_filter'])) && $format === 'png') {
            // first digit: compression level (default: 7)
            if (isset($options['png_compression_level'])) {
                if ($options['png_compression_level'] < 0 || $options['png_compression_level'] > 9) {
                    throw new InvalidArgumentException('png_compression_level option should be an integer from 0 to 9');
                }
                $compression = $options['png_compression_level'] * 10;
            } else {
                $compression = 70;
            }

            // second digit: compression filter (default: 5)
            if (isset($options['png_compression_filter'])) {
                if ($options['png_compression_filter'] < 0 || $options['png_compression_filter'] > 9) {
                    throw new InvalidArgumentException('png_compression_filter option should be an integer from 0 to 9');
                }
                $compression += $options['png_compression_filter'];
            } else {
                $compression += 5;
            }
            $v = \Imagick::getVersion();
            preg_match('/ImageMagick ([0-9]+\.[0-9]+\.[0-9]+)/', $v['versionString'], $v);
            if (version_compare($v[1], '6.8.7') < 0 ) {
                //Use this for ImageMagick releases before 6.8.7-5
                $vips->setImageCompressionQuality($compression);
            } else {
            //Use this for ImageMagick releases after 6.8.7-5
                $vips->setCompressionQuality($compression);
            }
        }

        if (isset($options['resolution-units']) && isset($options['resolution-x']) && isset($options['resolution-y'])) {
            if ($options['resolution-units'] == ImageInterface::RESOLUTION_PIXELSPERCENTIMETER) {
                $vips->setImageUnits(\Imagick::RESOLUTION_PIXELSPERCENTIMETER);
            } elseif ($options['resolution-units'] == ImageInterface::RESOLUTION_PIXELSPERINCH) {
                $vips->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
            } else {
                throw new RuntimeException('Unsupported image unit format');
            }

            $filter = ImageInterface::FILTER_UNDEFINED;
            if (!empty($options['resampling-filter'])) {
                $filter = $options['resampling-filter'];
            }

            $image->setImageResolution($options['resolution-x'], $options['resolution-y']);
            $image->resampleImage($options['resolution-x'], $options['resolution-y'], $this->getFilter($filter), 0);
        }
    }

    /**
     * Gets specifically formatted color string from Color instance
     *
     * @param ColorInterface $color
     *
     * @return \ImagickPixel
     */
    private function getColor(ColorInterface $color)
    {
        $pixel = new \ImagickPixel((string) $color);
        $pixel->setColorValue(\Imagick::COLOR_ALPHA, $color->getAlpha() / 100);

        return $pixel;
    }

    /**
     * Checks whether given $fill is linear and opaque
     *
     * @param FillInterface $fill
     *
     * @return Boolean
     */
    private function isLinearOpaque(FillInterface $fill)
    {
        return $fill instanceof Linear && $fill->getStart()->isOpaque() && $fill->getEnd()->isOpaque();
    }

    /**
     * Performs optimized gradient fill for non-opaque linear gradients
     *
     * @param Linear $fill
     */
    private function applyFastLinear(Linear $fill)
    {
        $gradient = new \Imagick();
        $size     = $this->getSize();
        $color    = sprintf('gradient:%s-%s', (string) $fill->getStart(), (string) $fill->getEnd());

        if ($fill instanceof Horizontal) {
            $gradient->newPseudoImage($size->getHeight(), $size->getWidth(), $color);
            $gradient->rotateImage(new \ImagickPixel(), 90);
        } else {
            $gradient->newPseudoImage($size->getWidth(), $size->getHeight(), $color);
        }

        $this->vips->compositeImage($gradient, \Imagick::COMPOSITE_OVER, 0, 0);
        $gradient->clear();
        $gradient->destroy();
    }

    /**
     * Internal
     *
     * Get the mime type based on format.
     *
     * @param string $format
     *
     * @return string mime-type
     *
     * @throws RuntimeException
     */
    private function getMimeType($format)
    {
        static $mimeTypes = array(
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'png'  => 'image/png',
            'wbmp' => 'image/vnd.wap.wbmp',
            'xbm'  => 'image/xbm',
            'webp' => 'image/webp',
        );

        if (!isset($mimeTypes[$format])) {
            throw new RuntimeException(sprintf('Unsupported format given. Only %s are supported, %s given', implode(", ", array_keys($mimeTypes)), $format));
        }

        return $mimeTypes[$format];
    }

    /**
     * Sets colorspace and image type, assigns the palette.
     *
     * @param PaletteInterface $palette
     *
     * @throws InvalidArgumentException
     */
    private function setColorspace(PaletteInterface $palette)
    {
        $typeMapping = array(
            // We use Matte variants to preserve alpha
            //
            // (the constants \Imagick::IMGTYPE_TRUECOLORMATTE and \Imagick::IMGTYPE_GRAYSCALEMATTE do not exist anymore in Imagick 7,
            // to fix this the former values are hard coded here, the documentation under http://php.net/manual/en/imagick.settype.php
            // doesn't tell us which constants to use and the alternative constants listed under
            // https://pecl.php.net/package/imagick/3.4.3RC1 do not exist either, so we found no other way to fix it as to hard code
            // the values here)
            PaletteInterface::PALETTE_CMYK      => defined('\Imagick::IMGTYPE_TRUECOLORMATTE') ? \Imagick::IMGTYPE_TRUECOLORMATTE : 7,
            PaletteInterface::PALETTE_RGB       => defined('\Imagick::IMGTYPE_TRUECOLORMATTE') ? \Imagick::IMGTYPE_TRUECOLORMATTE : 7,
            PaletteInterface::PALETTE_GRAYSCALE => defined('\Imagick::IMGTYPE_GRAYSCALEMATTE') ? \Imagick::IMGTYPE_GRAYSCALEMATTE : 3,
        );

        if (!isset(static::$colorspaceMapping[$palette->name()])) {
            throw new InvalidArgumentException(sprintf('The palette %s is not supported by Imagick driver', $palette->name()));
        }

        $this->vips->setType($typeMapping[$palette->name()]);
        $this->vips->setColorspace(static::$colorspaceMapping[$palette->name()]);
        $this->palette = $palette;
    }

    /**
     * Older imagemagick versions does not support colorspace conversions.
     * Let's detect if it is supported.
     *
     * @return Boolean
     */
    private function detectColorspaceConversionSupport()
    {
        if (null !== self::$supportsColorspaceConversion) {
            return self::$supportsColorspaceConversion;
        }
//FIXME:: not tested
        return self::$supportsColorspaceConversion = method_exists('Jcupitt\Vips\Image', 'colourspace');
    }

    /**
     * Returns the filter if it's supported.
     *
     * @param string $filter
     *
     * @return string
     *
     * @throws InvalidArgumentException If the filter is unsupported.
     */
    private function getFilter($filter = ImageInterface::FILTER_UNDEFINED)
    {
        static $supportedFilters = array(
            ImageInterface::FILTER_UNDEFINED => \Imagick::FILTER_UNDEFINED,
            ImageInterface::FILTER_BESSEL    => \Imagick::FILTER_BESSEL,
            ImageInterface::FILTER_BLACKMAN  => \Imagick::FILTER_BLACKMAN,
            ImageInterface::FILTER_BOX       => \Imagick::FILTER_BOX,
            ImageInterface::FILTER_CATROM    => \Imagick::FILTER_CATROM,
            ImageInterface::FILTER_CUBIC     => \Imagick::FILTER_CUBIC,
            ImageInterface::FILTER_GAUSSIAN  => \Imagick::FILTER_GAUSSIAN,
            ImageInterface::FILTER_HANNING   => \Imagick::FILTER_HANNING,
            ImageInterface::FILTER_HAMMING   => \Imagick::FILTER_HAMMING,
            ImageInterface::FILTER_HERMITE   => \Imagick::FILTER_HERMITE,
            ImageInterface::FILTER_LANCZOS   => \Imagick::FILTER_LANCZOS,
            ImageInterface::FILTER_MITCHELL  => \Imagick::FILTER_MITCHELL,
            ImageInterface::FILTER_POINT     => \Imagick::FILTER_POINT,
            ImageInterface::FILTER_QUADRATIC => \Imagick::FILTER_QUADRATIC,
            ImageInterface::FILTER_SINC      => \Imagick::FILTER_SINC,
            ImageInterface::FILTER_TRIANGLE  => \Imagick::FILTER_TRIANGLE
        );

        if (!array_key_exists($filter, $supportedFilters)) {
            throw new InvalidArgumentException(sprintf(
                'The resampling filter "%s" is not supported by Imagick driver.',
                $filter
            ));
        }

        return $supportedFilters[$filter];
    }
}
