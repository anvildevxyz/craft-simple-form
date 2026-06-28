<?php

namespace anvildev\simpleform\pdf;

/**
 * Engine seam for PDF generation (#143). An implementation wraps a concrete PDF
 * library (dompdf in v1); {@see \anvildev\simpleform\services\PdfService}
 * selects one only when its backing library is installed, so the feature degrades
 * gracefully when no engine is available.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
interface PdfEngineInterface
{
    /**
     * Whether this engine's backing library is installed and usable.
     */
    public function isAvailable(): bool;

    /**
     * Render an HTML document to PDF bytes.
     *
     * @param string $html the (already-rendered, sandboxed) HTML body
     * @return string the raw PDF bytes
     */
    public function renderHtml(string $html): string;
}
