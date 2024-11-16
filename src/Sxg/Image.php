<?php

namespace Sxg;

class Image
{
    const SXG_PALETTE_FORMAT_CLUT = 0;
    const SXG_PALETTE_FORMAT_PWM = 1;
    const SXG_COLOR_FORMAT_16 = 1;
    const SXG_COLOR_FORMAT_256 = 2;
    protected $version = 2;
    protected $backgroundColor = 0;
    protected $packingType = 0;
    protected $paletteType = self::SXG_PALETTE_FORMAT_PWM;

    protected $rgbPalette;
    protected $sxgPalette;
    protected $pixels;
    protected $colorFormat = self::SXG_COLOR_FORMAT_16;
    protected $width;
    protected $height;
    protected static $clut = [
        0,
        10,
        21,
        31,
        42,
        53,
        63,
        74,
        85,
        95,
        106,
        117,
        127,
        138,
        149,
        159,
        170,
        181,
        191,
        202,
        213,
        223,
        234,
        245,
        255,
    ];

    /**
     * @param int $paletteType
     */
    public function setPaletteType($paletteType)
    {
        $this->paletteType = $paletteType;
    }

    /**
     * @param int $height
     */
    public function setHeight($height)
    {
        $this->height = $height;
    }

    /**
     * @param int $width
     */
    public function setWidth($width)
    {
        $this->width = $width;
    }

    /**
     * @return int
     */
    public function getColorFormat()
    {
        return $this->colorFormat;
    }

    /**
     * @param int $colorFormat
     */
    public function setColorFormat($colorFormat)
    {
        $this->colorFormat = (int)$colorFormat;
    }

    /**
     * @return int
     */
    public function getBackgroundColor()
    {
        return $this->backgroundColor;
    }

    /**
     * @param int $backgroundColor
     */
    public function setBackgroundColor($backgroundColor)
    {
        $this->backgroundColor = $backgroundColor;
    }

    /**
     * @return mixed
     */
    public function getRgbPalette()
    {
        return null;
    }

    /**
     * @param mixed $palette
     * @return \Sxg\Image
     */
    public function setRgbPalette($palette)
    {
        $this->rgbPalette = [];
        foreach ($palette as $color) {
            $this->rgbPalette[] = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, $color];
        }
        return $this;
    }

    public function importFromGd($gdObject)
    {
        if ($gdObject = $this->resizeGdObject($gdObject)) {
            if ($gdObject = $this->applyPaletteToGdObject($gdObject)) {
                $this->importGdObject($gdObject);
                return true;
            }
        }
        return false;
    }

    protected function resizeGdObject($gdObject)
    {
        $sourceGdWidth = imagesx($gdObject);
        $sourceGdHeight = imagesy($gdObject);
        if (!$this->width) {
            $this->width = $sourceGdWidth;
        }
        if (!$this->height) {
            $this->height = $sourceGdHeight;
        }
        $newObject = imagecreatetruecolor($this->width, $this->height);
        imagecopyresampled($newObject, $gdObject, 0, 0, 0, 0, $this->width, $this->height, $sourceGdWidth, $sourceGdHeight);
        return $newObject;
    }

    protected function applyPaletteToGdObject($gdObject)
    {
        if ($this->rgbPalette) {
            //create new object with restricted palette
            $newObject = imagecreate($this->width, $this->height);

            //create separate resource for palette holding.
            //this cannot be created in $newObject unfortunately due to GD conversion bug,
            //so we have to use intermediate object
            $paletteResource = imagecreate($this->width, $this->height);

            //assign sxg palette colors to palette holding resource
            foreach ($this->rgbPalette as $color) {
                imagecolorallocate($paletteResource, $color[0], $color[1], $color[2]);
            }

            //here is the trick: assign palette before copying
            imagepalettecopy($newObject, $paletteResource);

            //copy truecolor source to our new object with resampling. colors get replaced with palette as well.
            imagecopy($newObject, $gdObject, 0, 0, 0, 0, $this->width, $this->height);

            //trick: assign palette after copying as well
            imagepalettecopy($newObject, $paletteResource);
        } else {
            $newObject = imagecreatetruecolor($this->width, $this->height);
            imagecopy($newObject, $gdObject, 0, 0, 0, 0, $this->width, $this->height);
            if ($this->colorFormat === self::SXG_COLOR_FORMAT_16) {
                imagetruecolortopalette($newObject, false, 16);
            } elseif ($this->colorFormat === self::SXG_COLOR_FORMAT_256) {
                imagetruecolortopalette($newObject, false, 256);
            }
            imagecolormatch($gdObject, $newObject);
        }

        return $newObject;
    }

    public function getSxgData()
    {
        return $this->generateSxgData();
    }

    protected function generateSxgData()
    {
        //        +#0000 #04 #7f+"SXG" - 4 bytes signature
        //        +#0004 #01 1 byte of format version
        //        +#0005 #01 1 byte of background color (used for non-fullscreen images)
        //        +#0006 #01 1 byte of packing type (#00 - non-packed)
        //        +#0007 #01 1 byte of format (1 - 16c, 2 - 256c)
        //        +#0008 #02 2 bytes of width in pixels
        //        +#000a #02 2 bytes of height in pixels
        //
        //        (далее указываются смещения, для того, что бы можно было расширить заголовок)
        //        +#000c #02 2 bytes of shifting from current address until palette data
        //        +#000e #02 2 bytes of shifting from current address until pixels data
        //
        //        Palette start
        //        +#0010 #0200 512 bytes of palette
        //
        //        Pixels start (bitmap data)
        //        +#0210 #xxxx bitmap data

        $sxgPalette = $this->getSxgPalette();
        $sxgPixels = $this->getSxgPixels();

        $data = chr(0x7F) . 'SXG';
        $data .= chr($this->version);
        $data .= chr($this->backgroundColor);
        $data .= chr($this->packingType);
        $data .= chr($this->colorFormat);

        $data .= $this->littleEndian($this->width);
        $data .= $this->littleEndian($this->height);

        //shift until palette start
        $data .= $this->littleEndian(2);

        //shift until pixels start
        $data .= $this->littleEndian(count($sxgPalette) * 2);
        foreach ($sxgPalette as $sxgColor) {
            $data .= $this->littleEndian($sxgColor);
        }
        foreach ($sxgPixels as $sxgPixelsByte) {
            $data .= chr($sxgPixelsByte);
        }


        return $data;
    }

    protected function littleEndian($integer)
    {
        return chr($integer & 0xFF) . chr($integer >> 8 & 0xFF);
    }

    protected function bigEndian($integer)
    {
        return chr($integer >> 8 & 0xFF) . chr($integer & 0xFF);
    }

    public function getSxgPixels()
    {
        $sxgPixels = [];
        if ($this->colorFormat === self::SXG_COLOR_FORMAT_16) {
            $firstPixel = false;
            foreach ($this->pixels as $pixel) {
                if ($firstPixel === false) {
                    $firstPixel = $pixel;
                } else {
                    $sxgPixels[] = (($firstPixel & 0x1f) << 4) + ($pixel & 0x1f);
                    $firstPixel = false;
                }
            }
        } elseif ($this->colorFormat === self::SXG_COLOR_FORMAT_256) {
            foreach ($this->pixels as $pixel) {
                $sxgPixels[] = $pixel;
            }
        }
        return $sxgPixels;
    }

    public function getSxgPalette()
    {
        if (($this->sxgPalette === null) && $this->rgbPalette) {
            $this->sxgPalette = [];
            if ($this->paletteType === self::SXG_PALETTE_FORMAT_PWM) {
                foreach ($this->rgbPalette as $color) {
                    if ($color !== null){
                        $this->sxgPalette[] = ($color[0] >> 3 << 10) + ($color[1] >> 3 << 5) + ($color[2] >> 3) + 32768;
                    }
                }
            } elseif ($this->paletteType == self::SXG_PALETTE_FORMAT_CLUT) {
                foreach ($this->rgbPalette as $color) {
                    $this->sxgPalette[] = ($this->findClosestClutValue($color[0]) << 10) + ($this->findClosestClutValue($color[1]) << 5) + $this->findClosestClutValue($color[2]);
                }
            }
        }
        return $this->sxgPalette;
    }

    protected function importGdObject($gd)
    {
        if ($this->colorFormat === self::SXG_COLOR_FORMAT_16) {
            $this->rgbPalette = array_fill(0, 16, null);
        } elseif ($this->colorFormat === self::SXG_COLOR_FORMAT_256) {
            $this->rgbPalette = array_fill(0, 256, null);
        }
        $this->pixels = [];
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {

                $index = imagecolorat($gd, $x, $y);
                $this->pixels[] = $index;
                if ($this->rgbPalette[$index] === null) {
                    $color = imagecolorsforindex($gd, $index);
                    $this->rgbPalette[$index] = [$color['red'], $color['green'], $color['blue']];
                }
            }
        }
        ksort($this->rgbPalette);
    }

    protected function findClosestClutValue($colorByte)
    {
        $closest = null;
        $closestDifference = PHP_INT_MAX;
        foreach (self::$clut as $sxgColor => $clutValue) {
            if (($difference = abs($colorByte - $clutValue)) < $closestDifference || $closest === null) {
                $closestDifference = $difference;
                $closest = $sxgColor;
            }
        }
        return $closest;
    }
}