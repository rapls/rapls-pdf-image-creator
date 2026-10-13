/**
 * PDF Image Creator - "Link To" in the media window starts at this plugin's setting
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

    if (!link) {
        return;
    }

    const proto = wp.media.controller.Library.prototype;
    const original = proto.defaultDisplaySettings;

    proto.defaultDisplaySettings = function(attachment) {
        const settings = original.apply(this, arguments);

        // Only the PDFs whose insert this plugin makes: those with a thumbnail.
        if (settings && attachment && 'function' === typeof attachment.get
            && 'application/pdf' === attachment.get('mime') && attachment.get('picThumbnailId')) {
            settings.link = link;
        }

        return settings;
    };
})();
