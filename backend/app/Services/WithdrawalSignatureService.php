<?php

namespace App\Services;

use App\Exceptions\HttpException;
use Illuminate\Support\Facades\URL;

class WithdrawalSignatureService
{
    public function resolveImage(string $signatureUrl): array
    {
        $path = parse_url($signatureUrl, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/api/storage/v1/object/(signatory-signatures|signatures)/(.+)$#', $path, $matches)) {
            throw new HttpException(409, 'signature_unavailable', 'The stored signature is not a readable uploaded image. Re-upload the authorized signature.');
        }

        $bucket = $matches[1];
        $objectPath = rawurldecode($matches[2]);
        $segments = explode('/', $objectPath);
        if ($objectPath === '' || str_contains($objectPath, '\\') || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new HttpException(409, 'signature_unavailable', 'The stored signature path is invalid. Re-upload the authorized signature.');
        }

        $filePath = storage_path('app/private/' . $bucket . '/' . $objectPath);
        if (!is_file($filePath) || !is_readable($filePath) || filesize($filePath) > 2 * 1024 * 1024) {
            throw new HttpException(409, 'signature_unavailable', 'The stored signature image is missing, unreadable, or too large. Re-upload the authorized signature.');
        }

        $image = @getimagesize($filePath);
        $mimeType = $image['mime'] ?? null;
        if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new HttpException(409, 'signature_unavailable', 'The stored signature is not a supported image. Upload a PNG, JPEG, or WebP signature.');
        }

        $contents = file_get_contents($filePath);
        if ($contents === false || $contents === '') {
            throw new HttpException(409, 'signature_unavailable', 'The stored signature image could not be read. Re-upload the authorized signature.');
        }

        return [
            'data_uri' => 'data:' . $mimeType . ';base64,' . base64_encode($contents),
            'file_name' => basename($objectPath),
        ];
    }

    public function createPreviewUrl(string $signatureUrl): string
    {
        $this->resolveImage($signatureUrl);
        $path = parse_url($signatureUrl, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/api/storage/v1/object/(signatory-signatures|signatures)/(.+)$#', $path, $matches)) {
            throw new HttpException(409, 'signature_unavailable', 'The stored signature path is invalid.');
        }

        return URL::signedRoute(
            'storage.download',
            ['bucket' => $matches[1], 'path' => rawurldecode($matches[2])],
            now()->addHour()
        );
    }
}
