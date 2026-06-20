<?php

namespace fabianhaef\simpleform\pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * dompdf-backed {@see PdfEngineInterface} (#143). An optional dependency: the
 * class is only ever instantiated when `\Dompdf\Dompdf` exists, so requiring this
 * file without the library installed never fatals at runtime (the autoloader only
 * resolves it lazily through {@see PdfService}).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class DompdfEngine implements PdfEngineInterface
{
    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        return class_exists(Dompdf::class);
    }

    /**
     * @inheritdoc
     */
    public function renderHtml(string $html): string
    {
        $options = new Options();
        // Form authors override pdf.twig with their own HTML/CSS; remote images
        // stay off so a template can't be used to make the worker fetch arbitrary
        // URLs. Local assets render through the regular file paths.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
