<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OcrService
{
    private string $prompt = <<<TXT
Transcribe el comprobante exactamente como aparece en la imagen.

Reglas:
- Respeta el orden original.
- Respeta las etiquetas completas tal como aparecen.
- No separes palabras que pertenecen a la misma etiqueta.
- Conserva números y signos tal como se ven.
- No resumas.
- No expliques.
- Devuelve solo la transcripción.
TXT;

    public function processImageBytes(string $imageBytes, string $mimeType = 'image/jpeg'): array
    {
        $texto = $this->callOpenAiOcr($imageBytes, $mimeType);
        $cleanText = $this->cleanOcrText($texto);
        $fields = $this->extractFields($cleanText);

        return [
            'success' => true,
            'id_transaccion' => $fields['id_transaccion'],
            'fecha' => $fields['fecha'],
            'hora' => $fields['hora'],
            'sucursal' => $fields['sucursal'],
            'valor' => $fields['valor'],
            'ocr_text' => $texto,
            'clean_text' => $cleanText,
            'fields' => $fields,
        ];
    }

    public function processImageUrl(string $imageUrl): array
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => '*/*',
            ])
            ->get($imageUrl)
            ->throw();

        $contentTypeHeader = $response->header('Content-Type', 'image/jpeg');
        $contentType = trim(explode(';', $contentTypeHeader)[0] ?: 'image/jpeg');

        return $this->processImageBytes($response->body(), $contentType);
    }

    private function callOpenAiOcr(string $imageBytes, string $mimeType): string
    {
        $apiKey = (string) config('services.openai.key');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY no está configurada.');
        }

        $imageB64 = base64_encode($imageBytes);

        $payload = [
            'model' => 'gpt-4.1-mini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $this->prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$imageB64}",
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0,
        ];

        $response = Http::withToken($apiKey)
            ->timeout(90)
            ->retry(2, 200)
            ->post('https://api.openai.com/v1/chat/completions', $payload)
            ->throw()
            ->json();

        $content = data_get($response, 'choices.0.message.content');
        $texto = $this->normalizeOpenAiContent($content);

        if ($texto === '') {
            throw new RuntimeException('OpenAI no devolvió texto OCR.');
        }

        return $texto;
    }

    private function normalizeOpenAiContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }

            if (is_array($item)) {
                if (isset($item['text']) && is_string($item['text'])) {
                    $parts[] = $item['text'];
                } elseif (
                    isset($item['type'], $item['text']) &&
                    $item['type'] === 'text' &&
                    is_string($item['text'])
                ) {
                    $parts[] = $item['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    private function cleanOcrText(string $texto): string
    {
        $texto = str_replace('Transaccioacutén', 'Transacción', $texto);
        $texto = str_replace('Transaccioacute;n', 'Transacción', $texto);
        $texto = str_replace('IdTransaccion', 'ID Transacción', $texto);
        $texto = str_replace('Identificador de transacción', 'ID Transacción', $texto);
        $texto = str_replace('Identificador transacción', 'ID Transacción', $texto);
        $texto = str_replace('ldTransaccion', 'ID Transacción', $texto);
        $texto = str_replace('Id TTransaccion', 'ID Transacción', $texto);
        $texto = str_replace('ATM Transacción ID', 'ATM Transacción ID', $texto);
        $texto = str_replace('RECIBIDO :', 'RECIBIDO:', $texto);
        $texto = str_replace("\r", '', $texto);

        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n+/u', "\n", $texto) ?? $texto;

        return trim($texto);
    }

    private function extractFields(string $texto): array
    {
        $fields = [
            'id_transaccion' => null,
            'fecha' => null,
            'hora' => null,
            'sucursal' => null,
            'valor' => null,
        ];

        if (preg_match('/ATM\s*Transacci[oó]n\s*ID[:\s]*([0-9]{6,})/iu', $texto, $m)) {
            $fields['id_transaccion'] = $m[1];
        }

        if (! $fields['id_transaccion'] &&
            preg_match('/(?:^|\n)\s*(?:ID\s*Transacci[oó]n|Id\s*T?Transaccion|Id\s*Transaccion)[:\s]*([0-9]{6,})/iu', $texto, $m)) {
            $fields['id_transaccion'] = $m[1];
        }

        if (! $fields['id_transaccion'] &&
            preg_match('/(?:^|\n)\s*NRO\.\s*TRANSACCION[:\s]*([0-9]{6,})/iu', $texto, $m)) {
            $fields['id_transaccion'] = $m[1];
        }

        if (preg_match('/(?:Fecha\/Hora|Fecha)[:\s]*([0-9]{2}-[0-9]{2}-[0-9]{4}|[0-9]{2}\/[0-9]{2}\/[0-9]{4})(?:\s+([0-9]{2}:[0-9]{2}:[0-9]{2}))?/iu', $texto, $m)) {
            $fields['fecha'] = $m[1] ?? null;
            $fields['hora'] = $m[2] ?? null;
        }

        if (preg_match('/Sucursal[:\s]*(.+)/iu', $texto, $m)) {
            $fields['sucursal'] = trim($m[1]);
        }

        if (preg_match('/Valor\s+Ingresado[:\s]*(Gs\.?\s*[0-9\.\,]+)/iu', $texto, $m)) {
            $fields['valor'] = trim($m[1]);
        }

        if (! $fields['valor'] &&
            preg_match('/Valor\s+Entregado[:\s]*(Gs\.?\s*[0-9\.\,]+)/iu', $texto, $m)) {
            $fields['valor'] = trim($m[1]);
        }

        if (! $fields['valor'] &&
            preg_match('/RECIBIDO[:\s]*(Gs\.?\s*[0-9\.\,]+)/iu', $texto, $m)) {
            $fields['valor'] = trim($m[1]);
        }

        if (! $fields['valor'] &&
            preg_match('/Valor\s+recibido[:\s]*(Gs\.?\s*[0-9\.\,]+)/iu', $texto, $m)) {
            $fields['valor'] = trim($m[1]);
        }

        if (! $fields['valor'] &&
            preg_match('/Valor[:\s]*(Gs\.?\s*[0-9\.\,]+)/iu', $texto, $m)) {
            $fields['valor'] = trim($m[1]);
        }

        return $fields;
    }
}