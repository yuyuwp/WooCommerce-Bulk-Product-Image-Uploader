# WooCommerce Bulk Image Uploader

A simple WordPress plugin that lets you set featured images for WooCommerce products by pasting external image URLs in bulk.

## How It Works

1. Go to **WooCommerce → Bulk Image Uploader**
2. You'll see a table of all your products with a URL input field next to each one
3. Paste image URLs into the fields for the products you want to update
4. Click **"Upload and Save"**
5. The plugin downloads each image, saves it to your WordPress Media Library, and sets it as the product's featured image

## Features

- **Bulk paste**: Fill in as many URLs as you want, then upload them all with one click
- **Sequential processing**: Uploads one at a time to avoid server overload
- **Live progress**: Progress bar and per-product status indicators
- **Thumbnail preview**: See current thumbnails and watch them update in real-time
- **Search & filter**: Search by product name, filter by "Missing Image" / "Has Image"
- **Pagination**: 50 products per page for fast loading
- **Error handling**: Clear error messages per product if a URL fails

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## Installation

1. Upload the `wc-bulk-image-uploader` folder to `wp-content/plugins/`
2. Activate in **Plugins**
3. Find it under **WooCommerce → Bulk Image Uploader**

## License

GPL v2 or later
