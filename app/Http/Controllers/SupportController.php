<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SupportController extends Controller
{
    public function create(): View
    {
        return view('marketing.support');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'topic' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:15', 'max:5000'],
            'website' => ['nullable', 'size:0'],
        ]);

        $body = "Name: {$data['name']}\nEmail: {$data['email']}\nCompany address: ".($data['company_address'] ?? 'Not provided')."\nTopic: {$data['topic']}\n\n{$data['message']}";

        try {
            Mail::raw($body, function ($mail) use ($data): void {
                $mail->to('support@shatyainfo.com')
                    ->replyTo($data['email'], $data['name'])
                    ->subject('Shatya Info support: '.$data['topic']);
            });
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['message' => 'Your request could not be sent. Please email support@shatyainfo.com directly.']);
        }

        return back()->with('support_status', 'Your request has been sent to the support team.');
    }
}
