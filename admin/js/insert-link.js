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
 * setting, and what is picked there is sent with the insert.
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

    if (!link && !size) {
        return;
    }

    const isPdf = function(attachment) {
        return 'application/pdf' === attachment.get('mime') && !!attachment.get('picThumbnailId');
    };

    // The setting's values, on a display model or the settings that make one,
    // where they still hold what core started them at.
    const apply = function(target, from) {
        const get = function(key) { return 'function' === typeof target.get ? target.get(key) : target[key]; };
        const put = function(key, value) {
            if ('function' === typeof target.set) { target.set(key, value); } else { target[key] = value; }
        };
        if (link && get('link') === from.link) { put('link', link); }
        if (size && get('size') === from.size) { put('size', size); }
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
            apply(settings, settings);
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
            const library = this;
            const started = { link: settings.link, size: settings.size };
            attachment.once('change:picThumbnailId', function() {
                if (isPdf(attachment)) {
                    apply(library.display(attachment), started);
                }
            });
        }

        return settings;
    };

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
