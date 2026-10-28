<?php

namespace FlourishWooCommercePlugin\Importer;

defined('ABSPATH') || exit;

use FlourishWooCommercePlugin\API\FlourishAPI;

/**
 * Syncs Flourish item art into WooCommerce product images.
 *
 * Runs after each item import (flourish_item_imported). The work is queued
 * through Action Scheduler when available so full syncs and webhooks are not
 * slowed down by image downloads.
 *
 * Flourish stores each upload under a new S3 key, so the URL path (without
 * the presigned query string) identifies an image. Attachments remember that
 * path and are reused until the image is replaced in Flourish.
 */
class FlourishItemImages
{
    const ACTION = 'flourish_sync_item_images';
    const SOURCE_META = '_flourish_art_source';

    // First available type becomes the main image, the rest go to the gallery
    const IMAGE_TYPE_ORDER = ['product', 'packaging', 'label'];

    const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public $existing_settings;

    public function __construct($existing_settings)
    {
        $this->existing_settings = $existing_settings;
    }

    public function register_hooks()
    {
        add_action('flourish_item_imported', [$this, 'queue_image_sync'], 10, 2);
        add_action(self::ACTION, [$this, 'sync_images'], 10, 2);
    }

    public function queue_image_sync($product, $product_id)
    {
        if (empty($this->existing_settings['item_sync_options']['images'])) {
            return;
        }

        if (empty($product_id) || empty($product['flourish_item_id'])) {
            return;
        }

        $args = [(int) $product['flourish_item_id'], (int) $product_id];

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, $args, 'flourish', true);
            return;
        }

        $this->sync_images(...$args);
    }

    public function sync_images($item_id, $product_id)
    {
        $wc_product = wc_get_product($product_id);
        if (!$wc_product) {
            return;
        }

        $flourish_api = new FlourishAPI(
            $this->existing_settings['api_key'] ?? '',
            $this->existing_settings['url'] ?? '',
            $this->existing_settings['facility_id'] ?? ''
        );

        try {
            $art = $flourish_api->fetch_item_art($item_id);
        } catch (\Exception $e) {
            error_log("Error syncing images for Flourish item {$item_id}: " . $e->getMessage());
            return;
        }

        $urls = $this->order_by_image_type($art);

        $attachment_ids = [];
        foreach ($urls as $image_type => $url) {
            $attachment_id = $this->get_or_create_attachment($url, $item_id, $image_type, $product_id);
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
            }
        }

        // Every download failed; keep the current images and retry on the next sync
        if (!empty($urls) && empty($attachment_ids)) {
            return;
        }

        $previous_main_id = (int) $wc_product->get_image_id();
        $previous_gallery_ids = array_map('intval', $wc_product->get_gallery_image_ids());

        if (empty($urls)) {
            // Art was removed in Flourish: drop its images, keep any that were never from Flourish
            $main_image_id = $this->is_flourish_image($previous_main_id) ? 0 : $previous_main_id;
            $gallery_ids = array_values(array_filter($previous_gallery_ids, fn($id) => !$this->is_flourish_image($id)));
        } else {
            $main_image_id = array_shift($attachment_ids);
            $gallery_ids = $attachment_ids;
        }

        if ($previous_main_id === $main_image_id && $previous_gallery_ids === $gallery_ids) {
            return;
        }

        $wc_product->set_image_id($main_image_id);
        $wc_product->set_gallery_image_ids($gallery_ids);
        $wc_product->save();

        // Delete Flourish images that were replaced or removed; leave anything uploaded by hand
        $previous_ids = array_filter(array_merge([$previous_main_id], $previous_gallery_ids));
        foreach (array_diff($previous_ids, array_merge([$main_image_id], $gallery_ids)) as $old_id) {
            if ($this->is_flourish_image($old_id)) {
                wp_delete_attachment($old_id, true);
            }
        }
    }

    private function is_flourish_image($attachment_id)
    {
        return $attachment_id && get_post_meta($attachment_id, self::SOURCE_META, true);
    }

    private function order_by_image_type($art)
    {
        $urls_by_type = [];
        foreach ($art as $image) {
            if (!empty($image['url']) && !empty($image['image_type'])) {
                $urls_by_type[$image['image_type']] = $image['url'];
            }
        }

        $ordered = [];
        foreach (self::IMAGE_TYPE_ORDER as $image_type) {
            if (isset($urls_by_type[$image_type])) {
                $ordered[$image_type] = $urls_by_type[$image_type];
                unset($urls_by_type[$image_type]);
            }
        }

        // Any types Flourish adds later go after the known ones
        return $ordered + $urls_by_type;
    }

    private function get_or_create_attachment($url, $item_id, $image_type, $product_id)
    {
        $source = strtok($url, '?');

        $existing = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_key'    => self::SOURCE_META,
            'meta_value'  => $source,
            'fields'      => 'ids',
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            return (int) $existing[0];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp_file = download_url($url, 60);
        if (is_wp_error($tmp_file)) {
            error_log("Error downloading {$image_type} image for Flourish item {$item_id}: " . $tmp_file->get_error_message());
            return 0;
        }

        // S3 keys have no file extension, so name the file from its contents
        $mime_type = wp_get_image_mime($tmp_file);
        if (!isset(self::MIME_EXTENSIONS[$mime_type])) {
            @unlink($tmp_file);
            error_log("Unsupported {$image_type} image type for Flourish item {$item_id}: " . ($mime_type ?: 'unknown'));
            return 0;
        }

        $file = [
            'name'     => sprintf('flourish-%d-%s.%s', $item_id, sanitize_file_name($image_type), self::MIME_EXTENSIONS[$mime_type]),
            'tmp_name' => $tmp_file,
        ];

        $attachment_id = media_handle_sideload($file, $product_id);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            error_log("Error saving {$image_type} image for Flourish item {$item_id}: " . $attachment_id->get_error_message());
            return 0;
        }

        update_post_meta($attachment_id, self::SOURCE_META, $source);

        return (int) $attachment_id;
    }
}
