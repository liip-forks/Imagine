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

use Core\Operation\Grayscale;
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
use Imagine\Image\Palette\Color\Gray;
use Imagine\Image\Palette\ColorParser;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Image\PointInterface;
use Imagine\Image\ProfileInterface;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\PaletteInterface;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\Direction;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Extend;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;
use Jcupitt\Vips\Kernel;


/**
 * Image implementation using the Vips PHP extension
 */
class Image extends AbstractImage
{
    /**
     * @var \Jcupitt\Vips\Image
     */
    protected $vips;
    /**
     * @var Layers
     */
    private $layers;
    /**
     * @var PaletteInterface
     */
    private $palette;

    private $strip = false;

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
     * @param \Jcupitt\Vips\Image         vips
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
        $clone = clone $this->vips->copy();
        return new self($clone, $this->palette, clone $this->metadata);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function crop(PointInterface $start, BoxInterface $size)
    {
        //FIXME: this gives an error when $size is biger than image, does not with imagick
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
        } catch (VipsException $e) {
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
            $this->vips = $this->vips->flip(Direction::HORIZONTAL);
        } catch (VipsException $e) {
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
            $this->vips = $this->vips->flip(Direction::VERTICAL);
        } catch (VipsException $e) {
            throw new RuntimeException('Vertical Flip operation failed', $e->getCode(), $e);
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
        $this->strip = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function paste(ImageInterface $image, PointInterface $start)
    {
        //FIXME: implement in vips
        /** @var VipsImage $inVips */
        $inVips = $image->getVips();

        if (!$inVips->hasAlpha()) {
            if ($this->vips->hasAlpha()) {
                $inVips = $inVips->bandjoin([255]);
            }
        }

        if (!$this->vips->hasAlpha()) {
            if ($inVips->hasAlpha()) {
                $this->vips = $this->vips->bandjoin([255]);
            }
        }
        $image = $image->extendImage($this->getSize(), $start)->getVips();
        $this->vips = $this->vips->composite([$this->vips, $image], [2]);
        return $this;
    }

    protected function extendImage(BoxInterface $box, PointInterface $start) {
        $color = new \Imagine\Image\Palette\Color\RGB(new RGB(), [255,255,255], 0);
        if (!$this->vips->hasAlpha()) {
            $this->vips = $this->vips->bandjoin([255]);
        }
        $new = self::generateImage($box, $color);
        #$this->vips = $new;
        $this->vips = $new->insert($this->vips, $start->getX(), $start->getY());
        return $this;
    }

    public static function generateImage(BoxInterface $size, ColorInterface $color = null) {
        $width  = $size->getWidth();
        $height = $size->getHeight();
        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = null !== $color ? $color : $palette->color('fff');
        list($red, $green, $blue, $alpha) = self::getColorArrayAlpha($color);

        // Make a 1x1 pixel with the red channel and cast it to provided format.
        $pixel = VipsImage::black(1, 1)->add($red)->cast(BandFormat::UCHAR);
        // Extend this 1x1 pixel to match the origin image dimensions.
        $vips = $pixel->embed(0, 0, $width, $height, ['extend' => Extend::COPY]);
        $vips = $vips->copy(['interpretation' => self::getInterpretation($color->getPalette())]);
        // Bandwise join the rest of the channels including the alpha channel.
        $vips = $vips->bandjoin([
            $green,
            $blue,
            $alpha
        ]);
        return $vips;
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
            }
        } catch (VipsException $e) {
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

            switch ($angle) {
                case 0:
                case 360:
                    break;
                case 90:
                    $this->vips = $this->vips->rot90();
                    break;
                case 180:
                    $this->vips = $this->vips->rot180();
                    break;
                case 270:
                    $this->vips = $this->vips->rot270();
                    break;
                default:
                    if (!$this->vips->hasAlpha()) {
                        //FIXME, alpha channel with Grey16 isn't doing well on rotation. there's only alpha in the end
                        if ($this->vips->interpretation !== Interpretation::GREY16) {
                            $this->vips = $this->vips->bandjoin(255);
                        }
                    }
                    //needs upcoming vips 8.6
                    $this->vips = $this->vips->similarity(['angle' => $angle, 'background' => self::getColorArrayAlpha($color)]);

            }
        } catch (VipsException $e) {
            throw new RuntimeException('Rotate operation failed. ' . $e->getMessage(), $e->getCode(), $e);
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
        $options = $this->applyImageOptions($this->vips, $options, $path);
        $this->prepareOutput($options);
        $format = $options['format'];
        if ($format == 'jpg' || $format == 'jpeg') {
            return $this->vips->jpegsave($path, ['strip' => $this->strip, 'Q' => $options['jpeg_quality'], 'interlace' => true]);
        }
        else if ($format == 'png') {
            return $this->vips->pngsave($path, ['strip' => $this->strip, 'compression' => $options['png_compression_level']]);
        }
        else if ($format == 'webp') {
            return $this->vips->webpsave($path, ['strip' => $this->strip, 'Q' => $options['webp_quality'], 'lossless' => $options['webp_lossless']]);
        }
        else {
            //fallback to imagemagick, not sure pngsave is the best and fastest solution
            //FIXME: make this better configurable
            $imagickine = new \Imagine\Imagick\Imagine();
            $imagick = $imagickine->load($this->vips->pngsave_buffer(['interlace' => false]));
            return $imagick->save($path, $options);
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
        $options['format'] = $format;
        $this->prepareOutput($options);
        $options = $this->applyImageOptions($this->vips, $options);

        if ($format == 'jpg' || $format == 'jpeg') {
            return $this->vips->jpegsave_buffer(['strip' => $this->strip, 'Q' => $options['jpeg_quality'], 'interlace' => true]);
        }
        else if ($format == 'png') {
            return $this->vips->pngsave_buffer(['strip' => $this->strip, 'compression' => $options['png_compression_level']]);
        }
        else if ($format == 'webp') {
            return $this->vips->webpsave_buffer(['strip' => $this->strip, 'Q' => $options['webp_quality'], 'lossless' => $options['webp_lossless']]);
        }
        else {
            //fallback to imagemagick, not sure pngsave is the best and fastest solution
            //FIXME: and maybe make that more customizable
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
        //FIXME: implement in vips

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
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");

        return new Drawer($this->vips);
    }

    /**
     * {@inheritdoc}
     */
    public function effects()
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");

        return new Effects($this->vips);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize()
    {
        $width  = $this->vips->width;
        $height = $this->vips->height;
        return new Box($width, $height);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function applyMask(ImageInterface $mask)
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");
    }

    /**
     * {@inheritdoc}
     */
    public function mask()
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function fill(FillInterface $fill)
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");
    }

    /**
     * {@inheritdoc}
     */
    public function histogram()
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");
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
            $pixel = $this->vips->getpoint($point->getX(), $point->getY());

        } catch (VipsException $e) {
            throw new RuntimeException('Error while getting image pixel color', $e->getCode(), $e);
        }

        return $this->pixelToColor($pixel);
    }

    /**
     * Returns a color given a pixel, depending the Palette context
     *
     * Note : this method is public for PHP 5.3 compatibility
     *
     * @param array $pixel
     *
     * @return ColorInterface
     *
     * @throws InvalidArgumentException In case a unknown color is requested
     */
    public function pixelToColor(array $pixel)
    {
        if ($this->palette->supportsAlpha() && $this->vips->hasAlpha()) {
            $alpha = array_pop($pixel) / 255 * 100;
        } else {
            $alpha = null;
        }
        if ($this->palette() instanceof RGB) {
            return $this->palette()->color($pixel, (int) $alpha);
        }
        if ($this->palette() instanceof \Imagine\Image\Palette\Grayscale) {
            $alpha = array_pop($pixel) / 255 * 100;
            $g = (int) $pixel[0];
            return $this->palette()->color([$g, $g, $g], (int) $alpha);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function layers()
    {
        //FIXME: implement in vips
      //  throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");

        return $this->layers;
    }

    /**
     * {@inheritdoc}
     */
    public function usePalette(PaletteInterface $palette)
    {
        if (!isset(self::$colorspaceMapping[$palette->name()])) {
            throw new InvalidArgumentException(sprintf('The palette %s is not supported by Vips driver', $palette->name()));
        }

        /* FIXME: implement palette support.. */
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
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");

    }

    /**
     * Internal
     *
     * Flatten the image.
     */
    private function flatten()
    {
        try {
            return  $this->vips->flatten();
        } catch (VipsException $e) {
            throw new RuntimeException('Flatten operation failed', $e->getCode(), $e);
        }
    }

    /**
     * Internal
     *
     * Applies options before save or output
     *
     * @param VipsImage $image
     * @param array     $options
     * @param string    $path
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function applyImageOptions(VipsImage $vips, array $options, $path = null)
    {
        if (isset($options['format'])) {
            $format = $options['format'];
        } elseif ('' !== $extension = pathinfo($path, \PATHINFO_EXTENSION)) {
            $format = $extension;
        } else {
            //FIXME, may not work
            $format = pathinfo($vips->filename, \PATHINFO_EXTENSION);
        }
        $format = strtolower($format);
        $options['format'] = $format;

        if (!isset($options['jpeg_quality']) && in_array($format, array('jpeg', 'jpg', 'pjpeg'))) {
            $options['jpeg_quality'] = 92;
        }
        if (!isset($options['webp_quality']) && in_array($format, array('webp'))) {
            $options['webp_quality'] = 80; // FIXME: correct value?
        }
        if (!isset($options['webp_lossless']) && in_array($format, array('webp'))) {
            $options['webp_lossless'] = false;
        }


        if ($format === 'png') {
            if (!isset($options['png_compression_level'])) {
                $options['png_compression_level'] = 7;
            }
            //FIXME: implement different png_compression_filter
            if (!isset($options['png_compression_filter'])) {
                $options['png_compression_filter'] = 5;
            }

        }
        /** FIXME: do we need this?
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
         */
        return $options;
    }

    protected function updatePalette() {
        $this->palette = Imagine::createPalette($this->vips);
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
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");
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
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__ . " not implemented yet in the vips adapter.");
    }

    protected static function getInterpretation(PaletteInterface $palette) {
        if ($palette instanceof RGB) {
            return Interpretation::SRGB;
        }
        if ($palette instanceof Grayscale) {
            return Interpretation::GREY16;
        }
        if ($palette instanceof CMYK) {
            return Interpretation::CMYK;
        }
    }


    private function getColorArray(ColorInterface $color): array {
        return [$color->getValue(ColorInterface::COLOR_RED),
            $color->getValue(ColorInterface::COLOR_GREEN),
            $color->getValue(ColorInterface::COLOR_BLUE)
        ];
    }

    public static function getColorArrayAlpha(ColorInterface $color): array {
        if ($color->getPalette() instanceof RGB) {
            return [
                $color->getValue(ColorInterface::COLOR_RED),
                $color->getValue(ColorInterface::COLOR_GREEN),
                $color->getValue(ColorInterface::COLOR_BLUE),
                $color->getAlpha() / 100 * 255

            ];
        }
        if ($color->getPalette() instanceof Grayscale) {
            return [
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getAlpha() / 100 * 255
            ];
        }
    }

}
