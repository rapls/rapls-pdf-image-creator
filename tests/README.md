# Tests

```
php tests/run.php
```

No WordPress, no PHPUnit, no ImageMagick. `harness.php` stubs the handful of
WordPress functions and the Imagick surface these classes touch, so the suites
run against a bare PHP binary. They are excluded from the distributed zip via
`.gitattributes`.

| Suite | Covers |
|---|---|
| `test-colorprofile.php` | ICC discovery, file validation, transient caching, conversion mode selection, fallback on exception |
| `test-engine.php` | Format normalization, background resolution per output format, alpha handling, diagnostics, Status tab row |
| `test-pipeline.php` | Full `ImagickEngine::convert()` run, asserting the exact call order |

## What these do and do not prove

The stubs record *which* Imagick calls happen and in *what order*. That is
enough to catch the class of bug this suite was written for — the colour
conversion running after the resize, the ICC profiles being applied in the
wrong order, JPEG silently flattening onto black — because all of those are
ordering and branching mistakes.

They cannot prove the resulting pixels are right. Verifying that needs a real
ImageMagick with a PDF delegate and real ICC profiles. The regression matrix
below is from the fix specification; the rows marked ✓ are covered here, the
rest need a live environment.

| # | Case | Covered |
|---|---|---|
| R-1 | Ordinary RGB PDF is unchanged | ✓ |
| R-2 | CMYK PDF converts through ICC | ✓ (ordering only) |
| R-3 | PDF/X-1a | takes the R-2 path; needs a real file to confirm |
| R-4 | Transparent regions flatten onto the background | ✓ |
| R-5 | Grayscale PDF is left alone | ✓ |
| R-6 | No ICC profile on the server | ✓ |
| R-7 | Imagick or the PDF delegate missing | ✓ |
| R-8 | JPEG / PNG / WebP agree on colour | ✓ |
| R-9 | Pages after the first | ✓ |
| R-10 | Bulk regeneration matches single generation | shares one code path; needs WordPress to confirm |

## Checking actual colour

With ImageMagick available, build a CMYK fixture and measure the result:

```bash
magick -size 400x100 \
  \( -size 100x100 xc:'cmyk(63%,13%,67%,0%)' \) \
  \( -size 100x100 xc:'cmyk(75%,15%,45%,0%)' \) \
  \( -size 100x100 xc:'cmyk(0%,80%,90%,0%)' \) \
  \( -size 100x100 xc:'cmyk(90%,70%,0%,0%)' \) \
  +append -colorspace cmyk cmyk-patches.pdf
```

Patch 1 is the colour from the original bug report. Through the arithmetic
conversion it lands on `#5EDF54`; through a coated-stock profile it lands near
`#4CA85E`, against a reference of `#4BA958` in the source PDF. Judge with
CIEDE2000 rather than by eye — ΔE2000 ≤ 5 passes.
