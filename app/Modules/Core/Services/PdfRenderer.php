<?php

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTML -> PDF using mPDF, which shapes Arabic text correctly (joining + RTL) with the bundled
 * DejaVu / XB Riyaz fonts. Pure PHP: works on shared hosting without binaries.
 */
class PdfRenderer
{
    public function render(string $html, string $title = 'document', string $format = 'A4'): string
    {
        $tmp = storage_path('app/mpdf');
        File::ensureDirectoryExists($tmp);
        $rtl = app()->getLocale() === 'ar';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'tempDir' => $tmp,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 12,
            'fontDir' => (new ConfigVariables)->getDefaults()['fontDir'],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'],
        ]);
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->SetTitle($title);
        $mpdf->SetCreator(config('app.name'));
        // Untrusted text is escaped by Blade; disallow remote resources in generated documents.
        $mpdf->showImageErrors = false;
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public function download(string $html, string $filename): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);

        return response($this->render($html, $safe), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
