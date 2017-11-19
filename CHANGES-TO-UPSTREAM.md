# Changes to upstream

This is our own little fork for stuff we need for rokka and didn't (yet) make it to the upstream of Imagine

## Fix alpha on rotate

Our ImageMagick 7 doesn't add an alpha layer on rotate, Imagine has to add an alpha layer explicitely.

* Branch: https://github.com/liip-forks/Imagine/tree/fix-alpha-on-rotate
* Merge Request: https://github.com/avalanche123/Imagine/pull/558
* Upstream Status: New

## Different way to set compression level on newer ImageMagick

The method to set png compression properly changed in 6.8.7 to setImageCompressionQuality

* Branch: https://github.com/liip-forks/Imagine/tree/png-compression-newer-imagemagick
* Merge Request: https://github.com/avalanche123/Imagine/pull/505
* Upstream Status: Reverted

## Webp support

Adds webp support to Imagine

* Branch: https://github.com/liip-forks/Imagine/tree/webp-support
* Merge Request: https://github.com/avalanche123/Imagine/pull/504
* Upstream Status: Missing support for gmagick?

## Remove finals in imagick class

Remove some finals in imagick classes to make them extensible

* Branch: https://github.com/liip-forks/Imagine/tree/remove-finals-from-imagick
* Upstream Status: Won't be merged.
