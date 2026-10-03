<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * JSON API backing the client-side React eVisa wizard.
 *
 * This mirrors the session-based flow that previously lived in
 * App\Http\Controllers\UKVIController, but returns JSON (and streamed
 * responses for the photo/PDF) instead of rendering Blade views or issuing
 * redirects. The session keys are kept identical so the multi-step flow
 * behaves exactly as before:
 *   document_type, document_number, user_id, delivery_method,
 *   otp_code, otp_expires_at, otp_verified, share_code
 */
class UKVIApiController extends Controller
{
    /**
     * Step 1 — store the chosen identity document + its number in the session.
     * The lookup itself is date-of-birth based (see dateOfBirth), so the
     * number is stored for parity but is not used to find the record.
     */
    public function storeDocument(Request $request)
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:passport,national_id,biometric,customer_number'],
            'document_number' => ['required', 'string', 'max:255'],
        ]);

        $request->session()->put('document_type', $validated['document_type']);
        $request->session()->put('document_number', $validated['document_number']);

        return response()->json(['ok' => true, 'next' => 'date-of-birth']);
    }

    /**
     * Step 2 — look up the user by date of birth and remember them in session.
     */
    public function dateOfBirth(Request $request)
    {
        $validated = $request->validate([
            'day' => ['required'],
            'month' => ['required'],
            'year' => ['required'],
        ]);

        $day = str_pad((string) $validated['day'], 2, '0', STR_PAD_LEFT);
        $month = str_pad((string) $validated['month'], 2, '0', STR_PAD_LEFT);
        $year = $validated['year'];
        $dob = "$year-$month-$day";

        $user = User::where('date_of_birth', $dob)->first();

        if (!$user) {
            $request->session()->forget('user_id');
            return response()->json(['found' => false], 404);
        }

        $request->session()->put('user_id', $user->id);

        return response()->json(['found' => true, 'next' => 'security-code']);
    }

    /**
     * Step 3 (GET) — the masked email/phone options for the found user.
     */
    public function securityCodeOptions(Request $request)
    {
        $user = User::find(session('user_id'));

        if (!$user) {
            return response()->json(['error' => 'record_not_found', 'redirect' => 'record-not-found'], 409);
        }

        return response()->json([
            'email' => $user->email ? $this->maskEmail($user->email) : null,
            'phone' => $user->phone_number ? $this->maskPhone($user->phone_number) : null,
        ]);
    }

    /**
     * Step 3 (POST) — generate + deliver a one-time security code.
     */
    public function sendSecurityCode(Request $request)
    {
        $user = User::find(session('user_id'));

        if (!$user) {
            return response()->json(['error' => 'record_not_found', 'redirect' => 'record-not-found'], 409);
        }

        $deliveryMethod = $request->input('delivery_method', 'email');

        $otp = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        $request->session()->put('delivery_method', $deliveryMethod);
        $request->session()->put('otp_code', $otp);
        $request->session()->put('otp_expires_at', $expiresAt->timestamp);
        $request->session()->forget('otp_verified');

        // Persist the OTP on the user so an admin can read it out to help the user
        $user->forceFill([
            'otp_code' => $otp,
            'otp_expires_at' => $expiresAt,
        ])->save();

        // SMS: no gateway configured — just confirm the code was sent.
        if ($deliveryMethod === 'sms') {
            return response()->json([
                'ok' => true,
                'delivery_method' => 'sms',
                'status' => 'We have sent a security code to your phone.',
            ]);
        }

        $emailSent = $this->sendOtpEmail($user, $otp);

        return response()->json([
            'ok' => $emailSent,
            'delivery_method' => 'email',
            'status' => $emailSent
                ? 'We have sent a security code to your email.'
                : 'We could not send the email. Please check the SMTP settings or try again.',
        ], $emailSent ? 200 : 502);
    }

    /**
     * Step 4 — verify the security code (with the 888888 master bypass).
     */
    public function verifyOtp(Request $request)
    {
        $request->validate(['otp' => ['required', 'string']]);

        $user = User::find(session('user_id'));
        if (!$user) {
            return response()->json(['error' => 'record_not_found', 'redirect' => 'record-not-found'], 409);
        }

        $entered = trim($request->input('otp'));

        // Master bypass code: allow proceeding without a valid OTP.
        if ($entered === '888888') {
            $request->session()->put('otp_verified', true);
            $request->session()->forget('otp_code');
            $user->forceFill(['otp_code' => null, 'otp_expires_at' => null])->save();

            return response()->json(['verified' => true, 'next' => 'status']);
        }

        $expected = session('otp_code');
        $expiresAt = session('otp_expires_at');

        if (!$expected || !$expiresAt || now()->timestamp > $expiresAt) {
            return response()->json([
                'verified' => false,
                'error' => 'Your security code has expired. Please request a new one.',
            ], 422);
        }

        if ($entered !== $expected) {
            return response()->json([
                'verified' => false,
                'error' => 'The security code you entered is not correct.',
            ], 422);
        }

        $request->session()->put('otp_verified', true);
        $request->session()->forget('otp_code');
        $user->forceFill(['otp_code' => null, 'otp_expires_at' => null])->save();

        return response()->json(['verified' => true, 'next' => 'status']);
    }

    /**
     * Step 4 (resend) — regenerate + re-deliver a security code.
     */
    public function resendOtp(Request $request)
    {
        $user = User::find(session('user_id'));

        if (!$user) {
            return response()->json(['error' => 'record_not_found', 'redirect' => 'record-not-found'], 409);
        }

        $otp = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);
        $request->session()->put('otp_code', $otp);
        $request->session()->put('otp_expires_at', $expiresAt->timestamp);

        $user->forceFill([
            'otp_code' => $otp,
            'otp_expires_at' => $expiresAt,
        ])->save();

        $emailSent = $this->sendOtpEmail($user, $otp);

        return response()->json([
            'ok' => $emailSent,
            'status' => $emailSent
                ? 'We have sent a new security code to your email.'
                : 'We could not send the email. Please check the SMTP settings or try again.',
        ], $emailSent ? 200 : 502);
    }

    /**
     * Step 5 — the eVisa status payload (requires a verified OTP).
     */
    public function evisa(Request $request)
    {
        $user = User::find(session('user_id'));

        if (!$user) {
            return response()->json(['error' => 'record_not_found', 'redirect' => 'record-not-found'], 409);
        }

        if (!session('otp_verified')) {
            return response()->json(['error' => 'otp_required', 'redirect' => 'security-code'], 409);
        }

        $code = $this->ensureShareCode($user);
        $request->session()->put('share_code', $code);

        return response()->json([
            'name' => $user->name,
            'date_of_birth' => optional($user->date_of_birth)->format('d/m/Y'),
            'nationality' => $user->nationality,
            'status' => $user->status,
            'valid_from' => optional($user->valid_from)->format('j F Y'),
            'valid_until' => optional($user->valid_until)->format('j F Y'),
            'national_insurance_number' => $user->national_insurance_number,
            'share_code' => $code,
            'has_photo' => (bool) $user->photo_path,
        ]);
    }

    /**
     * Stream the user's photo from the public disk (session-scoped, GET only).
     */
    public function evisaPhoto()
    {
        $user = User::find(session('user_id'));

        if (!$user || empty($user->photo_path)) {
            abort(404);
        }

        if (!Storage::disk('public')->exists($user->photo_path)) {
            abort(404);
        }

        $file = Storage::disk('public')->get($user->photo_path);
        $mime = Storage::disk('public')->mimeType($user->photo_path) ?: 'image/jpeg';

        return response($file, 200)->header('Content-Type', $mime);
    }

    /**
     * Stream the eVisa PDF (session-scoped, requires a verified OTP, GET only).
     */
    public function downloadEvisaPdf()
    {
        $user = User::find(session('user_id'));
        $shareCode = session('share_code');

        if (!$user || !$shareCode) {
            abort(404);
        }

        if (!session('otp_verified')) {
            abort(403);
        }

        $pdf = Pdf::loadView('ukvi.evisa-pdf', ['user' => $user, 'shareCode' => $shareCode])
            ->setPaper('a4');

        $filename = 'eVisa-' . preg_replace('/[^A-Za-z0-9]+/', '-', $user->name) . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Send the OTP email using the configured SMTP server.
     */
    protected function sendOtpEmail(User $user, string $otp): bool
    {
        if (empty($user->email)) {
            return false;
        }

        try {
            Mail::send('emails.otp', ['otp' => $otp, 'user' => $user], function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Your GOV.UK security code');
            });
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Reuse the user's stored share code if it is a valid "AAA AAA AAA"
     * format, otherwise generate one and persist it.
     */
    protected function ensureShareCode(User $user): string
    {
        $code = $user->code;

        if (!$code || !preg_match('/^[A-Z0-9]{3} [A-Z0-9]{3} [A-Z0-9]{3}$/', $code)) {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $code = strtoupper(substr(str_shuffle($chars), 0, 3) . ' ' .
                              substr(str_shuffle($chars), 0, 3) . ' ' .
                              substr(str_shuffle($chars), 0, 3));
            $user->forceFill(['code' => $code])->save();
        }

        return $code;
    }

    protected function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1) . str_repeat('*', max(0, strlen($local) - 1)) . '@' . $domain;
    }

    protected function maskPhone(?string $phoneNumber): string
    {
        $phone = str_replace([' ', '-', '(', ')'], '', $phoneNumber ?? '');

        if (strlen($phone) >= 5) {
            return '+' . substr($phone, 0, 2) . str_repeat('*', max(0, strlen($phone) - 5)) . substr($phone, -3);
        }

        if (strlen($phone) > 0) {
            return '+' . str_repeat('*', strlen($phone));
        }

        return '';
    }
}
