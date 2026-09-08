# Bundled ICC profile

This directory holds the sRGB profile the plugin attaches to colour-managed
output. It is first in the search order, ahead of anything discovered on the
host, so a profile placed here decides what every generated thumbnail is
tagged with.

## Why it is bundled

The conversion needs two profiles: a CMYK one to say what the incoming page
means, and an sRGB one to say what the outgoing image means. Without both, the
plugin falls back to arithmetic, and greens come out fluorescent — measured at
32 ΔE from a colour-managed conversion of the same page.

The CMYK side can be found on the host: Ghostscript ships `default_cmyk.icc`
and any server that renders PDFs at all has Ghostscript on it. The sRGB side
cannot be treated the same way, because the destination profile is *embedded
in every image the plugin writes*. Reading a file the host installed is one
thing; copying it into images a site then serves to the public is another.
So this one ships with the plugin, under a licence that permits exactly that.

## What to put here

`sRGB2014.icc`, from the ICC's own profile registry:

    https://www.color.org/srgbprofiles.xalter

Save it as `icc/sRGB2014.icc`. Do not alter it — the licence permits
redistribution of the file as published, and altering it brings conditions.

Check it afterwards:

    php tools/check-icc.php icc/sRGB2014.icc

## Licence

From the ICC profile library's general terms
(https://registry.color.org/profile-library/):

> This profile is made available by the International Color Consortium, and
> may be copied, distributed, embedded, made, used, and sold without
> restriction. Altered versions of this profile shall have the original
> identification and copyright information removed and shall not be
> misrepresented as the original profile.

Compatible with GPL-2.0-or-later, and it names embedding explicitly, which is
the use this plugin makes of it.

## What must not go here

Ghostscript's `iccprofiles/` are Artifex's, under Ghostscript's AGPL. The
plugin reads `default_cmyk.icc` from the host when it finds one, because a
source profile drives the transform and is then replaced — no copy of it
reaches the output. A destination profile is copied into every thumbnail, so
an AGPL file here would put AGPL bytes into images the site distributes.
Nothing from Ghostscript belongs in this directory.
