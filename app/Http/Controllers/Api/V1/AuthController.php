<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RequestOtpRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\MobileOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function requestOtp(RequestOtpRequest $request, MobileOtpService $otp): JsonResponse
    {
        $otp->request($request->string('mobile_number')->toString());

        return response()->json(['message' => 'If this mobile number is linked to an active account, an OTP has been sent.']);
    }

    public function verifyOtp(VerifyOtpRequest $request, MobileOtpService $otp): JsonResponse
    {
        $user = $otp->verify(
            $request->string('mobile_number')->toString(),
            $request->string('otp')->toString(),
        );

        if ($user === null) {
            throw ValidationException::withMessages(['otp' => ['The OTP is invalid or expired.']]);
        }

        return $this->tokenResponse($user, $request->string('device_name')->toString());
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->with('employee')
            ->where('email', $request->string('email')->toString())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->tokenResponse($user, $request->string('device_name')->toString());
    }

    private function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        $tokenName = $deviceName ?: 'field-api';
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
