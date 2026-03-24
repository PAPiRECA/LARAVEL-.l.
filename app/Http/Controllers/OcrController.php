<?php

namespace App\Http\Controllers;

use App\Services\OcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OcrController extends Controller
{
    public function __construct(
        private readonly OcrService $ocrService
    ) {}

    public function ocr(Request $request): JsonResponse
    {
        try {
            if (! $request->hasFile('file')) {
                return response()->json([
                    'success' => false,
                    'error' => 'No se recibió el archivo con el campo "file".',
                ], 400);
            }

            $file = $request->file('file');

            if (! $file || ! $file->isValid()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Error al subir el archivo.',
                ], 400);
            }

            $realPath = $file->getRealPath();

            if (! $realPath) {
                return response()->json([
                    'success' => false,
                    'error' => 'No se pudo obtener la ruta temporal del archivo.',
                ], 400);
            }

            $imageBytes = file_get_contents($realPath);

            if ($imageBytes === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'No se pudo leer el archivo subido.',
                ], 400);
            }

            $contentType = $file->getMimeType() ?: 'image/jpeg';

            return response()->json(
                $this->ocrService->processImageBytes($imageBytes, $contentType)
            );
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function ocrFromJson(Request $request): JsonResponse
    {
        try {
            $imageUrl = trim((string) $request->input('image_url', ''));

            if ($imageUrl === '' || strcasecmp($imageUrl, 'null') === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'image_url vacío o null desde Unify',
                    'received_image_url' => $imageUrl,
                ]);
            }

            if (! preg_match('/^https?:\/\//i', $imageUrl)) {
                return response()->json([
                    'success' => false,
                    'error' => 'image_url no tiene protocolo válido',
                    'received_image_url' => $imageUrl,
                ]);
            }

            return response()->json(
                $this->ocrService->processImageUrl($imageUrl)
            );
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

