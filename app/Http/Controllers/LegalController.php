<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LegalController extends Controller
{
    private const DOCUMENTS = [
        'service-agreement' => ['title' => 'Service Agreement', 'file' => 'service-agreement.md'],
        'data-processing-agreement' => ['title' => 'Data Processing Agreement', 'file' => 'data-processing-agreement.md'],
        'privacy' => ['title' => 'Privacy Notice', 'file' => 'privacy-notice.md'],
        'trial-terms' => ['title' => '30-Day Free Trial Terms', 'file' => 'trial-terms.md'],
    ];

    public function show(string $document): View
    {
        abort_unless(isset(self::DOCUMENTS[$document]), 404);

        return view('marketing.legal', [
            'title' => self::DOCUMENTS[$document]['title'],
            'document' => $document,
            'content' => $this->content($document),
        ]);
    }

    public function downloadDpa(): Response
    {
        $pdf = Pdf::loadView('marketing.legal-pdf', [
            'content' => $this->content('data-processing-agreement'),
        ])->setPaper('a4');

        return $pdf->download('shatya-info-data-processing-agreement.pdf');
    }

    private function content(string $document): string
    {
        $markdown = file_get_contents(base_path('docs/legal/'.self::DOCUMENTS[$document]['file']));

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
