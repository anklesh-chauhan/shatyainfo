<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Quote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Services\OptionalModuleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\FontMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class SalesDocumentPdfController extends Controller
{
    public function preview(Request $request, string $type, int $id): Response
    {
        return $this->previewDocument($this->authorizedDocument($request, $type, $id), $type);
    }

    public function previewDocument(Quote|SalesOrder|SalesInvoice $document, string $type): Response
    {
        $document->load(['items.itemMaster', 'contactDetail', 'taxDetails']);

        $organization = Organization::with('addresses')->first();

        $pdf = Pdf::loadView('pdf.sales-document', compact('document', 'organization'));

        // Force render before adding page_script
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $fontMetrics = new FontMetrics($canvas, $dompdf->getOptions());

        $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($organization, $document) {
            $text = ($organization?->name ?? '')." - Document No {$document->document_number} - Page $pageNumber of $pageCount";
            $font = $fontMetrics->getFont('Helvetica', 'normal');
            $size = 9;

            $width = $fontMetrics->getTextWidth($text, $font, $size);
            $x = $canvas->get_width() - $width - 20; // right-align
            $y = $canvas->get_height() - 25;         // 25px from bottom

            $canvas->text($x, $y, $text, $font, $size);
        });

        $response = $pdf->stream(strtolower($type).'-'.$document->document_number.'.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function download(Request $request, string $type, int $id): Response
    {
        return $this->downloadDocument($this->authorizedDocument($request, $type, $id), $type);
    }

    public function downloadDocument(Quote|SalesOrder|SalesInvoice $document, string $type): Response
    {
        $document->load(['items.itemMaster', 'contactDetail', 'taxDetails']);

        $organization = Organization::first();

        $pdf = Pdf::loadView('pdf.sales-document', compact('document', 'organization'));

        // Force render before adding page_script
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $fontMetrics = new FontMetrics($canvas, $dompdf->getOptions());

        $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
            $text = "Page $pageNumber of $pageCount";
            $font = $fontMetrics->getFont('Helvetica', 'normal');
            $size = 9;

            $width = $fontMetrics->getTextWidth($text, $font, $size);
            $x = $canvas->get_width() - $width - 20; // right-align
            $y = $canvas->get_height() - 25;         // 25px from bottom

            $canvas->text($x, $y, $text, $font, $size);
        });

        $response = $pdf->download(strtolower($type).'-'.$document->document_number.'.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function authorizedDocument(Request $request, string $type, int $id): Quote|SalesOrder|SalesInvoice
    {
        $user = $request->user('web');
        abort_unless($user, 401);

        $model = match (strtolower($type)) {
            'quote' => Quote::class,
            'salesorder' => SalesOrder::class,
            'salesinvoice' => SalesInvoice::class,
            default => abort(404, 'Invalid document type'),
        };

        $permissionKey = class_basename($model);
        abort_unless(app(OptionalModuleService::class)->navigationEnabled($permissionKey), 403);
        Gate::forUser($user)->authorize('viewAny', $model);

        $document = $model::query()->applyVisibility($permissionKey, $user)->findOrFail($id);
        Gate::forUser($user)->authorize('view', $document);

        return $document;
    }
}
