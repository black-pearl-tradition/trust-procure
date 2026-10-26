<?php

require_once __DIR__ . '/security.php';

/** Maximum accepted size for a proof-of-payment image (5 MB). */
const PAYMENT_PROOF_MAX_BYTES = 5242880;

/** Allowed proof-of-payment extensions (must stay in sync with payment-proof.php). */
function payment_proof_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

/**
 * Canonical directory for proof-of-payment images, outside the web root:
 * C:\xampp\tps_private\payment_proofs (sibling of htdocs, not inside it).
 * Single source of truth — both the uploader and payment-proof.php use this.
 */
function payment_proof_directory(): string
{
    // __DIR__ = <project>/includes/helpers → up 4 = web-server docroot parent
    // (C:\xampp on this XAMPP setup), keeping proofs outside the web root.
    // Normalised to forward slashes so Windows/Unix path handling matches.
    return str_replace('\\', '/', dirname(__DIR__, 4)) . '/tps_private/payment_proofs';
}

/**
 * All directories to search when serving a proof image.
 * First entry is canonical; the second is the legacy location produced by an
 * earlier path bug (htdocs/tps_private/...) so old uploads keep displaying.
 */
function payment_proof_directories(): array
{
    $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));
    // Legacy location from the earlier path bug: project root's SIBLING
    // (htdocs/tps_private/...), i.e. one level above the project dir.
    $legacy = str_replace('\\', '/', dirname($projectRoot)) . '/tps_private/payment_proofs';
    $seen = [];
    foreach ([payment_proof_directory(), $legacy] as $directory) {
        // Skip duplicates so the same folder is never scanned twice.
        $key = rtrim(str_replace('\\', '/', $directory), '/');
        if (!isset($seen[$key])) {
            $seen[$key] = $directory;
        }
    }
    return array_values($seen);
}

/**
 * Resolve a stored proof filename to an absolute path on disk, checking the
 * canonical directory first and the legacy directory as a fallback.
 * Returns null when the file cannot be found (or the name is unsafe).
 */
function resolve_payment_proof_path(string $storedName): ?string
{
    $filename = basename(trim($storedName));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }
    foreach (payment_proof_directories() as $directory) {
        $resolvedDir = realpath($directory);
        if ($resolvedDir === false) {
            continue;
        }
        $candidate = realpath($resolvedDir . DIRECTORY_SEPARATOR . $filename);
        if (
            $candidate !== false && is_file($candidate)
            && str_starts_with($candidate, $resolvedDir . DIRECTORY_SEPARATOR)
        ) {
            return $candidate;
        }
    }
    return null;
}

/**
 * MIME types served for proof-of-payment images (extension => MIME).
 */
function payment_proof_mime_types(): array
{
    return [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
}

/**
 * Handle a single uploaded image file.
 * Returns the stored filename on success, or null if no file was uploaded.
 * Throws RuntimeException on validation failure.
 */
function handle_upload(array $file, string $subfolder): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file could not be uploaded.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded file appears to be empty.');
    }

    $safeSubfolder = normalize_upload_subfolder($subfolder);
    $basename = basename((string) ($file['name'] ?? 'upload'));
    $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

    // Proofs must be web-displayable images: the viewer only serves these
    // types, so reject anything else here with a friendly message instead
    // of saving a file that can never be displayed.
    if ($safeSubfolder === 'payments') {
        if (!in_array($extension, payment_proof_allowed_extensions(), true)) {
            throw new RuntimeException('Proof of payment must be a JPG, PNG, or WebP image.');
        }
        if ($size > PAYMENT_PROOF_MAX_BYTES) {
            throw new RuntimeException('Proof of payment must be 5 MB or smaller.');
        }
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new RuntimeException('The uploaded file is not a valid image.');
        }
    }

    $filename = bin2hex(random_bytes(8));
    if ($extension !== '') {
        $filename .= '.' . $extension;
    }

    if ($safeSubfolder === 'payments') {
        $uploadDirectory = payment_proof_directory();
    } else {
        $projectRoot = dirname(__DIR__, 2);
        $uploadDirectory = $projectRoot . '/uploads/' . $safeSubfolder;
    }

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The upload directory could not be created.');
    }
    $destination = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('The file could not be saved.');
    }

    return $filename;
}

/**
 * Handle multiple uploaded image files from a single <input type="file" multiple>.
 * Returns an array of stored filenames (skipping any empty slots).
 * Throws RuntimeException on the first validation failure.
 */
function handle_multi_upload(array $files, string $subfolder): array
{
    $stored = [];

    if (empty($files['name']) || !is_array($files['name'])) {
        return $stored;
    }

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $single = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        $result = handle_upload($single, $subfolder);
        if ($result) {
            $stored[] = $result;
        }
    }

    return $stored;
}
