# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Rapls PDF Image Creator is a WordPress plugin that automatically generates thumbnail images from PDF files using ImageMagick (Imagick PHP extension). When a PDF is uploaded to the Media Library, the plugin converts the first page to an image and attaches it as a thumbnail.

## Architecture

### Namespace & Autoloading

All classes are under the `Rapls\PDFImageCreator` namespace. The autoloader in `rapls-pdf-image-creator.php` maps `Rapls\PDFImageCreator\ClassName` to `includes/ClassName.php` and `Rapls\PDFImageCreator\Engine\ClassName` to `includes/Engine/ClassName.php`.

### Core Classes

- **Plugin** (`includes/Plugin.php`): Singleton entry point that initializes all components. Access via `Plugin::getInstance()`.
- **Generator** (`includes/Generator.php`): Core PDF-to-image conversion logic. Manages conversion engine, generates/deletes thumbnails, and provides thumbnail accessor methods.
- **Settings** (`includes/Settings.php`): Wrapper for `rapls_pic_settings` option. Provides typed getters for all configuration values.
- **MediaLibrary** (`includes/MediaLibrary.php`): Hooks into WordPress media handling - auto-generates thumbnails on upload, deletes on removal, filters display in admin.
- **Admin** (`includes/Admin.php`): Settings page registration, sanitization, and rendering.
- **BulkProcessor** (`includes/BulkProcessor.php`): AJAX handlers for bulk thumbnail generation of existing PDFs.
- **Inserter** (`includes/Inserter.php`): Filters editor insertion to replace PDF links with thumbnail HTML.

### Engine System

The `includes/Engine/` directory implements a strategy pattern for PDF conversion:

- **EngineInterface**: Contract with `isAvailable()`, `getRequirements()`, and `convert()` methods
- **ImagickEngine**: Uses PHP's Imagick extension (requires PDF support)
- **ConversionResult**: Value object returned by `convert()` containing success/error status and image metadata

Note: GhostScript engine was removed in v1.0.5 per WordPress.org security requirements (exec() is not allowed).

### Data Model

- Generated thumbnails are WordPress attachments with parent set to the source PDF
- Post meta `_rapls_pic_thumbnail_id` on PDF stores the thumbnail attachment ID
- Post meta `_rapls_pic_is_thumbnail` marks generated images
- Post meta `_rapls_pic_source_pdf` on thumbnail links back to source PDF
- WordPress's `_thumbnail_id` (featured image) is also set on the PDF

### Filter Hooks

All hooks use `rapls_pdf_image_creator_` prefix. Key hooks:
- `rapls_pdf_image_creator_thumbnail_page` - Which PDF page to convert (default 0)
- `rapls_pdf_image_creator_thumbnail_max_width/height/quality/format/bgcolor` - Override settings
- `rapls_pdf_image_creator_before_generate` / `rapls_pdf_image_creator_after_generate` - Actions around generation

### Naming Conventions

All identifiers use `rapls_pic_` or `rapls_pdf_` prefix:
- Constants: `RAPLS_PIC_VERSION`, `RAPLS_PIC_PLUGIN_DIR`, etc.
- Options: `rapls_pic_settings`
- AJAX actions: `rapls_pic_bulk_scan`, `rapls_pic_bulk_generate`, etc.
- Nonce: `rapls_pic_admin`
- Template functions: `rapls_pic_get_thumbnail_url()`, `rapls_pic_has_thumbnail()`, etc.
- Shortcodes: `rapls_pdf_thumbnail`, `rapls_pdf_download_link`, etc.

## Development

### Local Testing

This plugin requires a WordPress environment with:
- ImageMagick PHP extension (Imagick) with PDF support

Test by uploading a PDF file in Media Library and verifying thumbnail generation.

### Settings Option

All settings stored in `rapls_pic_settings` option with defaults in activation hook. Key values:
- `max_width`, `max_height`: Image dimensions (default 1024)
- `quality`: JPEG quality 0-100 (default 90)
- `format`: `jpeg`, `png`, or `webp`
- `auto_generate`: Generate on upload
- `set_featured`: Set thumbnail as PDF's featured image


<claude-mem-context>

</claude-mem-context>