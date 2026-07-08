<?php

declare(strict_types=1);

namespace MailPanel\Security;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

final class QrCodeSvg
{
    /**
     * @return array{svg:string,data_uri:string,version:int,size:int}
     */
    public function render(string $payload, int $scale = 5, int $border = 4): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            throw new InvalidArgumentException('QR payload is empty.');
        }

        $version = $this->estimateVersion(strlen($payload));
        $matrixSize = 17 + 4 * $version;
        $quietZone = max(2, min($border, 8));
        $moduleScale = max(6, min($scale + 3, 12));
        $pixelSize = ($matrixSize + ($quietZone * 2)) * $moduleScale;

        $renderer = new ImageRenderer(
            new RendererStyle($pixelSize, $quietZone),
            new SvgImageBackEnd()
        );

        $svg = (new Writer($renderer))->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());
        $svg = preg_replace('/^<\?xml[^>]+>\s*/', '', trim($svg)) ?? trim($svg);

        return [
            'svg' => $svg,
            'data_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'version' => $version,
            'size' => $matrixSize,
        ];
    }

    private function estimateVersion(int $byteLength): int
    {
        $capacities = [
            1 => 14,
            2 => 26,
            3 => 42,
            4 => 62,
            5 => 84,
            6 => 106,
            7 => 122,
            8 => 152,
            9 => 180,
            10 => 213,
        ];

        foreach ($capacities as $version => $capacity) {
            if ($byteLength <= $capacity) {
                return $version;
            }
        }

        return 10;
    }
}
