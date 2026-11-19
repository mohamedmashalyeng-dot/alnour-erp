<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Services\DocumentNumberGenerator;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;

/**
 * Barcode / QR generation. Output is server-generated SVG (no user markup), safe to inline.
 */
class CodeGenerator
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    /**
     * Next unused internal EAN-13 (prefix 20-29 is reserved for in-store use).
     * Must run inside a transaction.
     */
    public function nextInternalBarcode(): string
    {
        $prefix = (string) config('erp.barcode_prefix', '29');
        do {
            $seq = $this->numbers->nextRaw('barcode');
            $body = $prefix.str_pad((string) $seq, 12 - strlen($prefix), '0', STR_PAD_LEFT);
            $code = $body.self::ean13CheckDigit($body);
        } while (Product::where('barcode', $code)->exists());

        return $code;
    }

    public function nextSku(): string
    {
        do {
            $seq = $this->numbers->nextRaw('sku');
            $sku = 'EW-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    public static function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function isValidEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code)
            && self::ean13CheckDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    public function barcodeSvg(string $code, int $height = 40, float $widthFactor = 1.6): string
    {
        $type = self::isValidEan13($code) ? new TypeEan13 : new TypeCode128;
        $barcode = $type->getBarcode($code);
        $renderer = new SvgRenderer;
        $renderer->setSvgType(SvgRenderer::TYPE_SVG_INLINE);

        return $renderer->render($barcode, $barcode->getWidth() * $widthFactor, $height);
    }

    public function qrSvg(string $data, int $scale = 4): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'scale' => $scale,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render($data);
    }

    public function qrPayload(Product $product): string
    {
        return $product->qr_code ?: $product->sku;
    }
}
