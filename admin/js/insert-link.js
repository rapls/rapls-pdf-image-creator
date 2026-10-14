/**
 * PDF Image Creator - "Link To" and Size in the media window start at this plugin's settings
 *
 * "Insert into post" links a PDF's thumbnail where the window's own Link To
 * says (Inserter::linkFor()). So that the plugin's "Link To" setting still
 * decides what happens when nobody touches the window, the window starts
 * there for a PDF with a thumbnail: the setting is the default, and a choice
 * made in the window wins (review of 1.4.33).
 *
 * Core keeps one display-settings model per attachment per window, made from
 * defaultDisplaySettings() the first time the attachment is shown; a change
 * made there is kept in that model, so it is not reset here when the
 * attachment is shown again.
 *
 * The window's Size works the same way: it starts at the "Insert size"
 * setting, and what is picked there is sent with the insert. A setting the
 * window does not offer -- medium_large, or a theme's size, which core's
 * list leaves out -- cannot be shown there: core would turn it into the
 * size it shows selected. Then the window is left as core starts it, and
 * while nobody changes it the insert follows the setting, as 1.4.20 did
 * (review of 1.4.35). The window then shows core's start, "Medium" say,
 * while the insert is the setting's size; once the Size is changed there at
 * all, what it says is what is inserted (review of 1.4.36).
 *
 * Loaded wherever the media window is (wp_enqueue_media).
 *
 * @package PDFImageCreator
 */

(function() {
    'use strict';

    if (!window.wp || !wp.media || !wp.media.controller || !wp.media.controller.Library
        || 'function' !== typeof wp.media.controller.Library.prototype.defaultDisplaySettings) {
        return;
    }

    // The setting's values, as the window names them.
    const LINKS = { file: 'file', attachment: 'post', none: 'none' };
    const data = window.raplsPicInsertLink || {};
    const link = Object.prototype.hasOwnProperty.call(LINKS, data.link) ? LINKS[data.link] : null;
    const size = 'string' === typeof data.size && '' !== data.size ? data.size : null;
    // None listed -- a filter that broke the list -- is not taken to mean
    // that nothing can be shown.
    const offeredNames = Array.isArray(data.sizes) && data.sizes.length ? data.sizes : null;

    if (!link && !size) {
        return;
    }

    const isPdf = function(attachment) {
        return 'application/pdf' === attachment.get('mime') && !!attachment.get('picThumbnailId');
    };

    // Whether the window's Size lists the setting's size for this PDF: a name
    // core offers, which the PDF's thumbnail has a file for.
    const offered = function(attachment) {
        if (offeredNames && -1 === offeredNames.indexOf(size)) {
            return false;
        }
        const own = attachment && 'function' === typeof attachment.get ? attachment.get('sizes') : null;
        return !own || 'object' !== typeof own || Object.prototype.hasOwnProperty.call(own, size);
    };

    // The setting's values, on a display model or the settings that make one,
    // where they still hold what core started them at. A size the window does
    // not list is not put there; where core started it is noted instead, so
    // that the insert can tell it was not changed.
    // Changed in the window, even back to where core started it, the size
    // is a choice: the note that it was left alone (picSizeStart) goes.
    const watchSize = function(model) {
        if (model && 'function' === typeof model.get && 'function' === typeof model.once
            && 'string' === typeof model.get('picSizeStart') && !model.picSizeWatched) {
            model.picSizeWatched = true;
            model.once('change:size', function() {
                if ('function' === typeof model.unset) { model.unset('picSizeStart'); } else { model.set('picSizeStart', null); }
            });
        }
    };

    const apply = function(target, from, attachment) {
        const get = function(key) { return 'function' === typeof target.get ? target.get(key) : target[key]; };
        const put = function(key, value) {
            if ('function' === typeof target.set) { target.set(key, value); } else { target[key] = value; }
        };
        if (link && get('link') === from.link) { put('link', link); }
        if (size && get('size') === from.size) {
            if (offered(attachment)) {
                put('size', size);
            } else {
                put('picSizeStart', get('size'));
            }
        }
    };

    const proto = wp.media.controller.Library.prototype;
    const original = proto.defaultDisplaySettings;

    proto.defaultDisplaySettings = function(attachment) {
        const settings = original.apply(this, arguments);

        if (!settings || !attachment || 'function' !== typeof attachment.get) {
            return settings;
        }

        // Only the PDFs whose insert this plugin makes: those with a thumbnail.
        if (isPdf(attachment)) {
            apply(settings, settings, attachment);
            return settings;
        }

        // A file still uploading in this window has no mime type or thumbnail
        // yet, and core makes its display model now and keeps it: the setting
        // was never applied, and core's rule for documents turned "None" into
        // "File" (review of 1.4.34). Apply it when the upload finishes,
        // unless the window has been changed by then.
        const name = attachment.get('filename');
        if (attachment.get('uploading') && 'string' === typeof name && /\.pdf$/i.test(name)
            && 'function' === typeof attachment.once && 'function' === typeof this.display) {
            // Known limits, left as they are (review of 1.4.35): a value
            // picked in the window that is the one it started at cannot be
            // told from no choice, so the setting is applied over it; a file
            // that gets no thumbnail keeps this listener until the window
            // goes, which then never runs; and another plugin that wraps
            // send.attachment and sends later sends without the size
            // (below), so the setting decides.
            const library = this;
            const started = { link: settings.link, size: settings.size };
            attachment.once('change:picThumbnailId', function() {
                if (isPdf(attachment)) {
                    const model = library.display(attachment);
                    apply(model, started, attachment);
                    // The note is made only now, after core's display()
                    // has run: watched here, or a size changed and changed
                    // back was taken for no choice (review after 1.4.36).
                    watchSize(model);
                }
            });
        }

        return settings;
    };

    // Changed in the window, even back to where core started it, the size
    // is a choice: the note that it was left alone goes. Without this, a
    // Size picked as "Medium" was taken for no choice and the setting's
    // medium_large inserted (review of 1.4.36). Only a value core never saw
    // change still cannot be told from no choice.
    if ('function' === typeof proto.display) {
        const display = proto.display;
        proto.display = function() {
            const model = display.apply(this, arguments);
            watchSize(model);
            return model;
        };
    }

    // The window's Size, sent with the insert. Core sends it for images only,
    // so the size picked there for a PDF was shown and ignored, and the
    // "Insert size" setting decided (review of 1.4.34). Inserter reads it as
    // 'image-size', the name core gives it for an image.
    const editor = wp.media.editor;
    if (!editor || !editor.send || 'function' !== typeof editor.send.attachment) {
        return;
    }
    const send = editor.send.attachment;
    editor.send.attachment = function(props, attachment) {
        const post = wp.media.post;
        if (!props || 'string' !== typeof props.size || '' === props.size || !attachment
            || 'application/pdf' !== attachment.mime || !attachment.picThumbnailId || 'function' !== typeof post) {
            return send.apply(this, arguments);
        }
        // The setting's size could not be shown, and the window still says
        // what core started it at: nothing was chosen there, so the setting
        // decides (Inserter::sizeFor() without 'image-size').
        if ('string' === typeof props.picSizeStart && props.size === props.picSizeStart) {
            return send.apply(this, arguments);
        }
        // Swapped for the length of the call only: if another plugin wraps
        // send.attachment and sends later, its request goes out after this
        // is put back, without the size, and the setting decides.
        wp.media.post = function(action, request) {
            if ('send-attachment-to-editor' === action && request && request.attachment) {
                request.attachment['image-size'] = props.size;
            }
            return post.apply(this, arguments);
        };
        try {
            return send.apply(this, arguments);
        } finally {
            wp.media.post = post;
        }
    };
})();
