<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PortalVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Portal email verification: a signed cross-device link, plus resend. */
class VerifyController extends Controller
{
    /**
     * The signed link from the verification email (no session needed, so it works
     * on any device). Marks the address verified and returns to the portal.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new NotFoundHttpException('Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect('/?verified=1');
    }

    /** Resend the verification email to the signed-in learner. */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email is already verified.']);
        }

        $user->notify(new PortalVerifyEmail());

        return response()->json(['message' => 'Verification email sent — check your inbox.']);
    }
}
